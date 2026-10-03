<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\ProcessSubscriberSunsetPolicyAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\CampaignType;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Marketing\Services\DomainHealthCheckService;

class MarketingEnterpriseSuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_webhook_lead_ingestion_creates_contact_and_awards_score(): void
    {
        // Setup an active workflow triggered by form/lead submission
        $workflow = MarketingWorkflow::create([
            'name' => 'Zapier & Ad Inbound Nurture',
            'trigger_type' => WorkflowTriggerType::FormSubmitted,
            'is_active' => true,
        ]);
        $workflow->steps()->create([
            'step_number' => 1,
            'type' => WorkflowStepType::UpdateContact,
            'config' => ['lifecycle_stage' => LifecycleStage::MarketingQualifiedLead->value],
        ]);

        $payload = [
            'email' => 'prospect@acme-enterprises.com',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'company' => 'Acme Enterprises',
            'job_title' => 'Chief Technology Officer',
            'phone' => '+1-555-0199',
            'source' => 'linkedin_lead_gen',
            'campaign' => 'q4-enterprise-cloud',
            'properties' => [
                'employees_count' => 500,
            ],
        ];

        $response = $this->postJson('/api/marketing/leads/webhook/linkedin_lead_gen', $payload);

        $response->assertOk();
        $response->assertJson([
            'success' => true,
            'is_new' => true,
            'message' => 'Lead successfully ingested into Odden CRM.',
        ]);

        $contact = Contact::where('email', 'prospect@acme-enterprises.com')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Grace', $contact->first_name);
        $this->assertSame('Hopper', $contact->last_name);
        $this->assertSame('Chief Technology Officer', $contact->job_title);
        $this->assertSame('linkedin_lead_gen', $contact->properties['lead_source']);
        $this->assertSame('q4-enterprise-cloud', $contact->properties['lead_campaign']);
        $this->assertSame(500, $contact->properties['employees_count']);

        // Check lead score was awarded (+15 pts for form submission)
        $this->assertSame(15, $contact->lead_score);

        // Check company association
        $this->assertTrue($contact->companies()->where('name', 'Acme Enterprises')->exists());

        // Check workflow enrollment
        $this->assertDatabaseHas('odden_marketing_workflow_enrollments', [
            'workflow_id' => $workflow->id,
            'contact_id' => $contact->id,
        ]);

        // Ingesting again with same email updates existing contact
        $response2 = $this->postJson('/api/marketing/leads/webhook/zapier', [
            'email' => 'prospect@acme-enterprises.com',
            'job_title' => 'EVP of Engineering',
        ]);
        $response2->assertOk();
        $response2->assertJson([
            'success' => true,
            'is_new' => false,
        ]);

        $contact->refresh();
        $this->assertSame('EVP of Engineering', $contact->job_title);
        $this->assertSame(30, $contact->lead_score); // 15 + 15
    }

    public function test_subscriber_sunset_policy_identifies_and_suppresses_dormant_subscribers(): void
    {
        // 1. Engaged contact (recent click) - should NOT sunset
        $engagedContact = Contact::create([
            'first_name' => 'Active',
            'email' => 'active@example.com',
            'last_marketing_email_sent_at' => now()->subDays(3),
        ]);

        // 2. Dormant contact (mailed for 95 days, 3 sends, 0 opens/clicks)
        $dormantContact = Contact::create([
            'first_name' => 'Cold',
            'email' => 'cold@example.com',
            'last_marketing_email_sent_at' => now()->subDays(3),
        ]);

        // 3. New contact (only 1 send received) - should NOT sunset
        $newContact = Contact::create([
            'first_name' => 'New',
            'email' => 'new@example.com',
            'last_marketing_email_sent_at' => now()->subDays(3),
        ]);

        // One campaign per send: a contact is a recipient of a campaign at most once.
        $campaigns = [];
        for ($i = 1; $i <= 3; $i++) {
            $campaigns[$i] = Campaign::create([
                'name' => "Historical Broadcast {$i}",
                'subject' => 'Archive news',
                'sender_name' => 'Odden',
                'sender_email' => 'news@odden.test',
                'status' => CampaignStatus::Sent,
                'type' => CampaignType::Regular,
            ]);
        }
        $campaign = $campaigns[1];

        // Create 3 sends for engaged contact, with 1 recent click
        for ($i = 1; $i <= 3; $i++) {
            CampaignRecipient::create([
                'campaign_id' => $campaigns[$i]->id,
                'contact_id' => $engagedContact->id,
                'email' => $engagedContact->email,
                'status' => 'sent',
                'tracking_token' => Str::random(40),
                'unsubscribe_token' => Str::random(40),
                'sent_at' => now()->subDays([1 => 95, 2 => 60, 3 => 3][$i]),
                'clicked_at' => $i === 1 ? now()->subDays(20) : null,
            ]);
        }

        // Create 3 sends for dormant contact with 0 opens/clicks
        for ($i = 1; $i <= 3; $i++) {
            CampaignRecipient::create([
                'campaign_id' => $campaigns[$i]->id,
                'contact_id' => $dormantContact->id,
                'email' => $dormantContact->email,
                'status' => 'sent',
                'tracking_token' => Str::random(40),
                'unsubscribe_token' => Str::random(40),
                'sent_at' => now()->subDays([1 => 95, 2 => 60, 3 => 3][$i]),
            ]);
        }

        // Create only 1 send for new contact
        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $newContact->id,
            'email' => $newContact->email,
            'status' => 'sent',
            'tracking_token' => Str::random(40),
            'unsubscribe_token' => Str::random(40),
            'sent_at' => now()->subDays(95),
        ]);

        $action = new ProcessSubscriberSunsetPolicyAction;

        // Run detection mode (flag only)
        $detectResults = $action->execute(inactivityDays: 90, minSendsReceived: 3, autoSuppress: false);
        $this->assertSame(1, $detectResults['dormant_detected_count']);
        $this->assertSame(0, $detectResults['auto_suppressed_count']);
        $this->assertSame([$dormantContact->id], $detectResults['contact_ids']);

        $dormantContact->refresh();
        $this->assertTrue($dormantContact->properties['is_sunset_dormant']);

        // Run auto-suppression mode
        $suppressResults = $action->execute(inactivityDays: 90, minSendsReceived: 3, autoSuppress: true);
        $this->assertSame(1, $suppressResults['auto_suppressed_count']);

        $this->assertTrue(MarketingSubscription::isSuppressed('cold@example.com'));
        $dormantContact->refresh();
        $this->assertTrue($dormantContact->properties['sunset_suppressed']);
    }

    public function test_sunset_subscribers_artisan_command_executes_successfully(): void
    {
        $exitCode = Artisan::call('marketing:sunset-subscribers', [
            '--days' => 90,
            '--min-sends' => 3,
        ]);

        $this->assertSame(0, $exitCode);
    }

    public function test_domain_health_check_service_evaluates_dns_standards(): void
    {
        $service = new DomainHealthCheckService;
        $results = $service->diagnose('odden.test', 'odden');

        $this->assertSame('odden.test', $results['domain']);
        $this->assertSame('pass', $results['overall_status']);
        $this->assertSame('pass', $results['spf']['status']);
        $this->assertSame('pass', $results['dmarc']['status']);
        $this->assertSame('pass', $results['dkim']['status']);
        $this->assertSame('pass', $results['mx']['status']);
    }
}
