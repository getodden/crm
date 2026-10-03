<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\EnrollContactInWorkflowAction;
use Odden\Marketing\Actions\GetCampaignAttributionAction;
use Odden\Marketing\Enums\AttributionModel;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class MultiChannelWorkflowsAndAttributionTest extends TestCase
{
    use RefreshDatabase;

    public function test_workflow_send_sms_step_executes_when_consent_is_granted(): void
    {
        $contactWithConsent = Contact::create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'hopper@navy.mil',
            'phone' => '+12025550192',
            'sms_consent' => true,
            'sms_consent_at' => now(),
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'SMS Notification Flow',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Send SMS Confirmation',
            'type' => WorkflowStepType::SendSms,
            'config' => [
                'message' => 'Odden: Your enterprise account has been provisioned!',
                'requires_consent' => true,
            ],
        ]);

        $action = new EnrollContactInWorkflowAction;
        $enrollment = $action->execute($workflow, $contactWithConsent);

        $this->assertNotNull($enrollment);
        $log = $enrollment->logs()->first();
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('Dispatched SMS to +12025550192', $log->action_taken);

        // Verify task logged on contact timeline
        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $contactWithConsent->id,
            'title' => 'Workflow SMS: SMS Notification Flow',
        ]);
    }

    public function test_workflow_send_sms_step_is_skipped_without_consent(): void
    {
        $contactWithoutConsent = Contact::create([
            'first_name' => 'No',
            'last_name' => 'Consent',
            'email' => 'noconsent@example.com',
            'phone' => '+12025550199',
            'sms_consent' => false,
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'Strict SMS Flow',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Send SMS',
            'type' => WorkflowStepType::SendSms,
            'config' => [
                'message' => 'Special promotion!',
                'requires_consent' => true,
            ],
        ]);

        $action = new EnrollContactInWorkflowAction;
        $enrollment = $action->execute($workflow, $contactWithoutConsent);

        $this->assertNotNull($enrollment);
        $log = $enrollment->logs()->first();
        $this->assertNotNull($log);
        $this->assertSame('skipped', $log->status);
        $this->assertStringContainsString('Skipped SMS (No SMS consent)', $log->action_taken);
    }

    public function test_workflow_webhook_step_dispatches_outbound_http_payload(): void
    {
        Http::fake([
            'https://hooks.slack.com/*' => Http::response(['ok' => true], 200),
        ]);

        $contact = Contact::create([
            'first_name' => 'Claude',
            'last_name' => 'Shannon',
            'email' => 'shannon@bell.labs',
        ]);

        $workflow = MarketingWorkflow::create([
            'name' => 'Slack Alert Webhook Flow',
            'trigger_type' => WorkflowTriggerType::Manual,
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'name' => 'Post to Slack Channel',
            'type' => WorkflowStepType::Webhook,
            'config' => [
                'url' => 'https://hooks.slack.com/services/T00/B00/X00',
                'method' => 'POST',
            ],
        ]);

        $action = new EnrollContactInWorkflowAction;
        $enrollment = $action->execute($workflow, $contact);

        $this->assertNotNull($enrollment);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://hooks.slack.com/services/T00/B00/X00'
                && $request['contact']['email'] === 'shannon@bell.labs';
        });

        $log = $enrollment->logs()->first();
        $this->assertNotNull($log);
        $this->assertSame('success', $log->status);
        $this->assertStringContainsString('Webhook POST to https://hooks.slack.com', $log->action_taken);
    }

    public function test_attribution_models_compute_weighted_pipeline_and_revenue(): void
    {
        $contact = Contact::create([
            'first_name' => 'John',
            'last_name' => 'von Neumann',
            'email' => 'jvn@ias.edu',
        ]);

        $campaign = Campaign::create([
            'name' => 'Enterprise Architecture Summit',
            'subject' => 'Summit Invitation',
            'sender_name' => 'Events',
            'sender_email' => 'events@odden.test',
        ]);

        $form = MarketingForm::create([
            'title' => 'Summit Registration',
            'slug' => 'summit-reg',
            'fields_schema' => [],
        ]);

        FormSubmission::create([
            'form_id' => $form->id,
            'contact_id' => $contact->id,
            'form_data' => ['email' => $contact->email],
            'utm_campaign' => 'enterprise-architecture-summit',
        ]);

        // Create associated deal
        $pipeline = Pipeline::create(['name' => 'Strategic Accounts', 'code' => 'strategic', 'is_default' => true]);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed-won', 'probability' => 100, 'sort_order' => 1, 'is_closed_won' => true]);

        $deal = Deal::create([
            'name' => 'IAS Supercomputing Contract',
            'amount' => 100000.00,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'status' => DealStatus::Won,
            'won_at' => now(),
        ]);
        $contact->associateWith($deal);

        // The same contact later engages with a nurture campaign, so the journey has two touches:
        // the summit form submission, then the follow-up email open.
        $followUp = Campaign::create([
            'name' => 'Follow-up Nurture',
            'subject' => 'Thanks for registering',
            'sender_name' => 'Events',
            'sender_email' => 'events@odden.test',
        ]);
        CampaignRecipient::create([
            'campaign_id' => $followUp->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'tok_jvn_followup',
            'unsubscribe_token' => 'unsub_jvn_followup',
            'opened_at' => now()->addHour(),
        ]);

        $action = new GetCampaignAttributionAction;

        // 1. First-Touch Attribution: all credit to the acquisition campaign, none to the follow-up
        $firstTouch = $action->execute($campaign, AttributionModel::FirstTouch);
        $this->assertSame(100000.00, $firstTouch['attributed_won_revenue']);
        $this->assertSame('first_touch', $firstTouch['attribution_model']);
        $this->assertSame(0.0, $action->execute($followUp, AttributionModel::FirstTouch)['attributed_won_revenue']);

        // 2. Last-Touch Attribution: the other way round
        $this->assertSame(0.0, $action->execute($campaign, AttributionModel::LastTouch)['attributed_won_revenue']);
        $this->assertSame(100000.00, $action->execute($followUp, AttributionModel::LastTouch)['attributed_won_revenue']);

        // 3. Linear Attribution: the two touches split the deal evenly
        $linear = $action->execute($campaign, AttributionModel::Linear);
        $this->assertSame(50000.00, $linear['attributed_won_revenue']);
        $this->assertSame('linear', $linear['attribution_model']);

        // 4. W-Shaped Attribution: with two touches, first and last share it evenly
        $wShaped = $action->execute($campaign, AttributionModel::WShaped);
        $this->assertSame(50000.00, $wShaped['attributed_won_revenue']);
        $this->assertSame('w_shaped', $wShaped['attribution_model']);

        // Every model hands out exactly the whole deal across the two campaigns.
        foreach (AttributionModel::cases() as $model) {
            $total = $action->execute($campaign, $model)['attributed_won_revenue'] + $action->execute($followUp, $model)['attributed_won_revenue'];
            $this->assertEqualsWithDelta(100000.00, $total, 0.01, $model->value);
        }
    }
}
