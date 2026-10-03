<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\DispatchSmsAction;
use Odden\Marketing\Actions\EnrollContactInWorkflowAction;
use Odden\Marketing\Actions\SuggestSubjectLinesAction;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\MarketingSmsMessage;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Marketing\Models\NpsResponse;
use Odden\Marketing\Models\NpsSurvey;

class HorizonMarketingExtensionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatch_sms_action_handles_consent_and_delivery(): void
    {
        $contactWithConsent = Contact::create([
            'first_name' => 'Katherine',
            'last_name' => 'Johnson',
            'email' => 'kjohnson@nasa.gov',
            'phone' => '+15551234567',
            'sms_consent' => true,
        ]);

        $action = new DispatchSmsAction;

        // 1. Success dispatch
        $sms = $action->execute($contactWithConsent, 'Hello {{contact.first_name}}! Welcome to Odden.');

        $this->assertInstanceOf(MarketingSmsMessage::class, $sms);
        $this->assertSame('delivered', $sms->status);
        $this->assertSame('+15551234567', $sms->phone_number);
        $this->assertStringContainsString('Hello Katherine!', $sms->message_body);
        $this->assertDatabaseHas('odden_marketing_sms_messages', [
            'id' => $sms->id,
            'contact_id' => $contactWithConsent->id,
            'status' => 'delivered',
        ]);

        // 2. Skipped without consent
        $contactNoConsent = Contact::create([
            'first_name' => 'Mary',
            'last_name' => 'Jackson',
            'email' => 'mjackson@nasa.gov',
            'phone' => '+15557654321',
            'sms_consent' => false,
        ]);

        $skippedSms = $action->execute($contactNoConsent, 'Special offer inside');
        $this->assertSame('skipped', $skippedSms->status);
        $this->assertSame('No SMS consent', $skippedSms->error_message);

        // 3. Failed without phone
        $contactNoPhone = Contact::create([
            'first_name' => 'Dorothy',
            'last_name' => 'Vaughan',
            'email' => 'dvaughan@nasa.gov',
            'sms_consent' => true,
        ]);

        $failedSms = $action->execute($contactNoPhone, 'Special offer inside');
        $this->assertSame('failed', $failedSms->status);
        $this->assertSame('Contact missing phone number', $failedSms->error_message);
    }

    public function test_workflow_executes_sms_step_and_stores_sms_message_record(): void
    {
        $contact = Contact::create([
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'email' => 'ada@computing.org',
            'phone' => '+15559876543',
            'sms_consent' => true,
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'SMS Onboarding Campaign',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'type' => WorkflowStepType::SendSms,
            'config' => [
                'message' => 'Hi {{contact.first_name}}, thanks for joining us!',
                'requires_consent' => true,
            ],
        ]);

        $action = new EnrollContactInWorkflowAction;
        $enrollment = $action->execute($workflow, $contact);

        $this->assertNotNull($enrollment);
        $this->assertDatabaseHas('odden_marketing_sms_messages', [
            'contact_id' => $contact->id,
            'status' => 'delivered',
            'phone_number' => '+15559876543',
        ]);

        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $contact->id,
            'title' => 'Workflow SMS: SMS Onboarding Campaign',
        ]);
    }

    public function test_nps_survey_flow_scoring_and_sentiment_adjustment(): void
    {
        $contact = Contact::create([
            'first_name' => 'Margaret',
            'last_name' => 'Hamilton',
            'email' => 'margaret@apollo.nasa.gov',
            'lead_score' => 20,
        ]);

        $survey = NpsSurvey::create([
            'name' => 'Post-Onboarding Satisfaction Survey',
            'is_active' => true,
        ]);

        $response = NpsResponse::createForContact($survey, $contact);

        $this->assertNotNull($response->token);
        $this->assertNull($response->responded_at);

        // 1. Submit rating via 1-click URL
        $getRatingUrl = route('odden.marketing.nps.rate', [
            'token' => $response->token,
            'score' => 10,
        ]);

        $responseHttp = $this->get($getRatingUrl);
        $responseHttp->assertStatus(200);
        $responseHttp->assertSee('Thank you for your rating!');
        $responseHttp->assertSee('What was the primary reason for your score?');

        $response->refresh();
        $this->assertSame(10, $response->score);
        $this->assertSame('promoter', $response->category);
        $this->assertNotNull($response->responded_at);

        // Verify lead scoring bonus for promoter (+10 points)
        $contact->refresh();
        $this->assertSame(30, $contact->lead_score);
        $this->assertSame(10, $contact->properties['latest_nps_score']);
        $this->assertSame('promoter', $contact->properties['nps_sentiment']);

        // 2. Submit qualitative comment
        $postFeedbackUrl = route('odden.marketing.nps.feedback', [
            'token' => $response->token,
        ]);

        $feedbackHttp = $this->post($postFeedbackUrl, [
            'feedback' => 'The system is outstanding, rock solid Apollo software!',
        ]);

        $feedbackHttp->assertRedirect();
        $feedbackHttp->assertSessionHas('success');

        $response->refresh();
        $this->assertSame('The system is outstanding, rock solid Apollo software!', $response->feedback);

        // 3. Test calculation of NPS score: (Promoters - Detractors) / Total * 100
        // Add a detractor
        $detractorContact = Contact::create([
            'first_name' => 'Detractor',
            'last_name' => 'User',
            'email' => 'detractor@example.com',
            'lead_score' => 50,
        ]);

        $detractorResp = NpsResponse::createForContact($survey, $detractorContact);
        $this->get(route('odden.marketing.nps.rate', ['token' => $detractorResp->token, 'score' => 3]))
            ->assertStatus(200);

        $detractorContact->refresh();
        // Lead score should have deducted 10 points for detractor
        $this->assertSame(40, $detractorContact->lead_score);
        $this->assertSame('detractor', $detractorContact->properties['nps_sentiment']);

        // 1 promoter (10) and 1 detractor (3) => NPS = (1 - 1) / 2 * 100 = 0
        $this->assertSame(0, $survey->calculateNpsScore());

        // Add another promoter (9)
        $promoter2 = Contact::create([
            'first_name' => 'Promoter',
            'last_name' => 'Two',
            'email' => 'p2@example.com',
        ]);
        $p2Resp = NpsResponse::createForContact($survey, $promoter2);
        $this->get(route('odden.marketing.nps.rate', ['token' => $p2Resp->token, 'score' => 9]));

        // 2 promoters and 1 detractor out of 3 => (2 - 1) / 3 * 100 = 33%
        $this->assertSame(33, $survey->calculateNpsScore());
    }

    public function test_subject_line_suggester_produces_template_based_variants(): void
    {
        $generator = new SuggestSubjectLinesAction;

        // Test urgent tone
        $urgent = $generator->execute('Spring VIP Demo', 'urgent');
        $this->assertCount(3, $urgent['suggestions']);
        $this->assertStringContainsString('Spring VIP Demo', $urgent['suggestions'][0]);
        $this->assertStringContainsString('⏳', $urgent['suggestions'][0]);
        $this->assertNotEmpty($urgent['variant_b']);
        $this->assertNotEmpty($urgent['preview_text']);
        $this->assertNotEmpty($urgent['rationale']);

        // Test curious tone
        $curious = $generator->execute('New Workflow Engine', 'curious', 'Growth Marketers');
        $this->assertCount(3, $curious['suggestions']);
        $this->assertStringContainsString('Growth Marketers', $curious['suggestions'][1]);
        $this->assertStringContainsString('👀', $curious['variant_b']);

        // Test bold tone
        $bold = $generator->execute('Cloud Migration', 'bold');
        $this->assertCount(3, $bold['suggestions']);
        $this->assertStringContainsString('🚀', $bold['suggestions'][0]);
    }
}
