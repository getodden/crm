<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\ApplyLeadScoringEventAction;
use Odden\Marketing\Actions\CalculateClosedLoopMetricsAction;
use Odden\Marketing\Actions\DispatchCampaignAction;
use Odden\Marketing\Actions\EvaluateSmartContentBlocksAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Marketing\Tests\Fixtures\User;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class MarketingEnterpriseDifferentiationSuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_frequency_capping_suppresses_fatigued_contacts(): void
    {
        config(['odden-marketing.fatigue_protection.enabled' => true]);
        config(['odden-marketing.fatigue_protection.min_hours_between_sends' => 24]);
        config(['odden-marketing.fatigue_protection.max_emails_per_7_days' => 2]);

        $freshContact = Contact::factory()->create([
            'email' => 'fresh@enterprise.test',
            'last_marketing_email_sent_at' => null,
        ]);

        $fatiguedContact = Contact::factory()->create([
            'email' => 'fatigued@enterprise.test',
            'last_marketing_email_sent_at' => now()->subHours(2), // Less than 24h interval
        ]);

        $template = MarketingTemplate::create([
            'name' => 'Newsletter',
            'subject' => 'Weekly Update',
            'body_html' => '<p>Hello {{contact.first_name}}</p>',
        ]);

        $campaign = Campaign::create([
            'name' => 'Q4 Announcement',
            'subject' => 'Important Q4 Announcement',
            'sender_name' => 'Odden Team',
            'sender_email' => 'marketing@odden.test',
            'template_id' => $template->id,
            'status' => CampaignStatus::Draft,
        ]);

        $results = app(DispatchCampaignAction::class)->execute(
            $campaign,
            new Collection([$freshContact, $fatiguedContact])
        );

        $this->assertEquals(2, $results['total_recipients']);
        $this->assertEquals(1, $results['delivered_count']);
        $this->assertEquals(1, $results['suppressed_count']);

        // Fresh contact was delivered, fatigued contact was protected
        $this->assertDatabaseHas('odden_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'contact_id' => $freshContact->id,
        ]);
        $this->assertDatabaseMissing('odden_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'contact_id' => $fatiguedContact->id,
        ]);
    }

    public function test_marketing_to_sales_instant_handoff_on_sql_qualification(): void
    {
        $salesRep = User::factory()->create(['name' => 'Alice AE']);
        $pipeline = Pipeline::create([
            'name' => 'Enterprise Sales',
            'code' => 'enterprise_sales',
        ]);
        $stage = PipelineStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Discovery',
            'code' => 'discovery',
            'sort_order' => 1,
            'probability' => 20,
        ]);

        $company = Company::create(['name' => 'Acme Global']);
        $contact = Contact::factory()->create([
            'first_name' => 'Bruce',
            'last_name' => 'Wayne',
            'email' => 'bruce@wayne.test',
            'lead_score' => 90,
            'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead,
            'owner_id' => null,
        ]);
        $contact->associateWith($company);

        // Apply scoring event that pushes lead score over 100 points
        config(['odden-marketing.sales_handoff.auto_handoff_on_sql' => true]);
        config(['odden-marketing.sales_handoff.sql_score_threshold' => 100]);
        config(['odden-marketing.sales_handoff.default_deal_amount' => 25000.00]);

        app(ApplyLeadScoringEventAction::class)->execute(
            contact: $contact,
            eventType: LeadScoringEventType::FormSubmission,
            description: 'Requested High-Intent Demo',
            points: 20 // 90 + 20 = 110 (promotes to SQL)
        );

        $contact->refresh();

        $this->assertEquals(LifecycleStage::SalesQualifiedLead, $contact->lifecycle_stage);
        $this->assertEquals(LeadStatus::InProgress, $contact->lead_status);
        $this->assertEquals($salesRep->id, $contact->owner_id);

        // Verify Deal was automatically generated and associated
        $this->assertDatabaseHas('odden_deals', [
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'amount' => 25000.00,
            'owner_id' => $salesRep->id,
        ]);

        // Verify urgent task logged on contact
        $this->assertDatabaseHas('odden_activities', [
            'subject_type' => (new Contact)->getMorphClass(),
            'subject_id' => $contact->id,
            'type' => 'task',
        ]);
    }

    public function test_dynamic_smart_content_and_inline_token_engine(): void
    {
        $company = Company::create([
            'name' => 'Apex Corp',
            'account_tier' => 'tier_1',
            'intent_surge' => true,
        ]);

        $vipCustomer = Contact::factory()->create([
            'first_name' => 'Sarah',
            'lifecycle_stage' => LifecycleStage::Customer,
            'lead_score' => 120,
        ]);
        $vipCustomer->associateWith($company);

        $lead = Contact::factory()->create([
            'first_name' => 'Bob',
            'lifecycle_stage' => LifecycleStage::Lead,
            'lead_score' => 15,
        ]);

        $evaluator = app(EvaluateSmartContentBlocksAction::class);

        // 1. Block-based test
        $blockHtml = '<div>[smart tier="tier_1"]VIP Enterprise Partner[/smart][smart default]Valued Guest[/smart]</div>';
        $this->assertStringContainsString('VIP Enterprise Partner', $evaluator->execute($blockHtml, $vipCustomer));
        $this->assertStringContainsString('Valued Guest', $evaluator->execute($blockHtml, $lead));

        // 2. Score threshold test
        $scoreHtml = '<div>[smart min_score="100"]Hot Lead[/smart][smart default]Cold Lead[/smart]</div>';
        $this->assertStringContainsString('Hot Lead', $evaluator->execute($scoreHtml, $vipCustomer));
        $this->assertStringContainsString('Cold Lead', $evaluator->execute($scoreHtml, $lead));

        // 3. Inline ternary token test
        $inlineHtml = 'Welcome! Your CTA is: {{smart:stage=customer?Upgrade to Enterprise:Start 14-Day Free Trial}}.';
        $this->assertEquals('Welcome! Your CTA is: Upgrade to Enterprise.', $evaluator->execute($inlineHtml, $vipCustomer));
        $this->assertEquals('Welcome! Your CTA is: Start 14-Day Free Trial.', $evaluator->execute($inlineHtml, $lead));

    }

    public function test_blade_style_smart_directives_are_evaluated(): void
    {
        $this->markTestIncomplete('@smart(...) is rewritten to [...] instead of [smart ...], so it is never evaluated; fixed by #29.');

        $customer = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer]);
        $lead = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead]);
        $evaluator = app(EvaluateSmartContentBlocksAction::class);

        $bladeHtml = 'Hello! @smart(stage="customer") Thank you for being a customer! @smart(default) Learn more about us. @endsmart';

        // Exact match: the directives must be consumed, not just the text present.
        $this->assertSame('Hello! Thank you for being a customer!', trim($evaluator->execute($bladeHtml, $customer)));
        $this->assertSame('Hello! Learn more about us.', trim($evaluator->execute($bladeHtml, $lead)));
    }

    public function test_closed_loop_revenue_and_velocity_analytics(): void
    {
        $pipeline = Pipeline::create([
            'name' => 'Direct Sales',
            'code' => 'direct_sales',
        ]);
        $stage = PipelineStage::create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Closed Won',
            'code' => 'closed_won',
            'sort_order' => 5,
            'probability' => 100,
        ]);

        $contact = Contact::factory()->create([
            'email' => 'client@acme.test',
            'created_at' => now()->subDays(14),
        ]);

        $campaign = Campaign::create([
            'name' => 'Growth Webinar',
            'subject' => 'Webinar Invite',
            'sender_name' => 'Odden Marketing',
            'sender_email' => 'marketing@odden.test',
            'status' => CampaignStatus::Sent,
            'delivered_count' => 1,
        ]);

        $campaign->recipients()->create([
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Sent,
            'sent_at' => now()->subDays(12),
        ]);

        $deal = Deal::create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'name' => 'Acme Renewal',
            'amount' => 50000.00,
            'status' => DealStatus::Won,
            'closed_at' => now(),
        ]);
        $contact->associateWith($deal, 'primary');

        $metrics = app(CalculateClosedLoopMetricsAction::class)->execute();

        $this->assertEquals(50000.00, $metrics['total_influenced_pipeline']);
        $this->assertEquals(50000.00, $metrics['total_closed_won_revenue']);
        $this->assertEquals(1, $metrics['won_deals_count']);
        $this->assertEquals(100.0, $metrics['marketing_win_rate']);
        $this->assertEquals(14.0, $metrics['average_sales_cycle_days']);
        $this->assertNotEmpty($metrics['top_campaigns']);
        $this->assertEquals('Growth Webinar', $metrics['top_campaigns'][0]['name']);
        $this->assertEquals(50000.00, $metrics['top_campaigns'][0]['won_revenue']);
    }

    public function test_inbound_webhook_workflow_enrollment(): void
    {
        $workflow = MarketingWorkflow::create([
            'name' => 'PLG Product Onboarding',
            'is_active' => true,
            'trigger_type' => WorkflowTriggerType::InboundWebhook,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'type' => WorkflowStepType::UpdateContact,
            'config' => ['field' => 'lead_status', 'value' => 'connected'],
        ]);

        $response = $this->postJson("/api/marketing/workflows/{$workflow->id}/enroll", [
            'email' => 'developer@startup.io',
            'first_name' => 'Dev',
            'last_name' => 'Ops',
            'company' => 'CloudStack Inc',
            'trigger_event' => 'stripe_checkout_completed',
            'properties' => [
                'plan_tier' => 'scale',
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'workflow_id' => $workflow->id,
        ]);
        $this->assertNotNull($response->json('enrollment_id'));

        $this->assertDatabaseHas('odden_contacts', [
            'email' => 'developer@startup.io',
            'first_name' => 'Dev',
        ]);

        $this->assertDatabaseHas('odden_companies', [
            'name' => 'CloudStack Inc',
        ]);

        $this->assertDatabaseHas('odden_marketing_workflow_enrollments', [
            'workflow_id' => $workflow->id,
            'contact_id' => (int) $response->json('contact_id'),
        ]);
    }
}
