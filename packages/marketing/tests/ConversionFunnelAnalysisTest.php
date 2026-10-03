<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\AnalyzeConversionFunnelAction;
use Odden\Marketing\Models\CustomBehavioralEvent;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Models\PageView;
use Odden\Marketing\Models\VisitorSession;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class ConversionFunnelAnalysisTest extends TestCase
{
    use RefreshDatabase;

    public function test_analyzes_multi_stage_conversion_funnel_with_rates_and_dropoffs(): void
    {
        // 1. Create Page Views (10 distinct sessions to /pricing)
        for ($i = 1; $i <= 10; $i++) {
            $session = VisitorSession::create([
                'visitor_token' => "vid_{$i}",
                'ip_address' => '127.0.0.1',
            ]);

            PageView::create([
                'session_id' => $session->id,
                'url' => 'https://odden.test/pricing',
                'path' => '/pricing',
                'title' => 'Pricing Plans',
                'created_at' => now(),
            ]);
        }

        // 2. Form Submissions (5 visitors submit demo request form)
        $form = MarketingForm::create([
            'title' => 'Demo Form',
            'slug' => 'demo-request',
            'fields_schema' => [],
        ]);

        for ($i = 1; $i <= 5; $i++) {
            FormSubmission::create([
                'form_id' => $form->id,
                'email' => "lead_{$i}@example.test",
                'form_data' => ['email' => "lead_{$i}@example.test"],
                'created_at' => now(),
            ]);
        }

        // 3. Behavioral Events (3 contacts activate product trial)
        for ($i = 1; $i <= 3; $i++) {
            $contact = Contact::create([
                'first_name' => "User{$i}",
                'email' => "lead_{$i}@example.test",
            ]);

            CustomBehavioralEvent::create([
                'event_name' => 'trial_activated',
                'contact_id' => $contact->id,
                'occurred_at' => now(),
            ]);
        }

        // 4. Deals Won (2 deals closed won)
        $pipeline = Pipeline::create(['name' => 'Sales', 'code' => 'sales']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'won', 'sort_order' => 1]);

        for ($i = 1; $i <= 2; $i++) {
            Deal::create([
                'name' => "Deal {$i}",
                'amount' => 15000.00,
                'status' => DealStatus::Won,
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'closed_at' => now(),
            ]);
        }

        $action = new AnalyzeConversionFunnelAction;

        $funnel = $action->execute([
            ['name' => 'Pricing Page Viewers', 'type' => 'page_visit', 'path' => '/pricing'],
            ['name' => 'Demo Form Submissions', 'type' => 'form_submission', 'form_slug' => 'demo-request'],
            ['name' => 'Trial Activations', 'type' => 'behavioral_event', 'event_name' => 'trial_activated'],
            ['name' => 'Closed Won Deals', 'type' => 'deal_won'],
        ]);

        $this->assertSame(10, $funnel['total_top_of_funnel']);
        $this->assertSame(2, $funnel['total_bottom_of_funnel']);
        $this->assertEquals(20.0, $funnel['overall_funnel_conversion_rate']); // 2 / 10 = 20%

        $steps = $funnel['steps'];
        $this->assertCount(4, $steps);

        // Step 1: 10 visitors
        $this->assertSame(10, $steps[0]['count']);
        $this->assertEquals(100.0, $steps[0]['conversion_rate']);

        // Step 2: 5 submissions (50% conversion from step 1, 5 dropoff)
        $this->assertSame(5, $steps[1]['count']);
        $this->assertEquals(50.0, $steps[1]['conversion_rate']);
        $this->assertSame(5, $steps[1]['dropoff_count']);
        $this->assertEquals(50.0, $steps[1]['dropoff_rate']);

        // Step 3: 3 trial activations (60% conversion from step 2, 2 dropoff)
        $this->assertSame(3, $steps[2]['count']);
        $this->assertEquals(60.0, $steps[2]['conversion_rate']);
        $this->assertSame(2, $steps[2]['dropoff_count']);
        $this->assertEquals(40.0, $steps[2]['dropoff_rate']);

        // Step 4: 2 deals won (66.7% conversion from step 3, 1 dropoff)
        $this->assertSame(2, $steps[3]['count']);
        $this->assertEquals(66.7, $steps[3]['conversion_rate']);
        $this->assertSame(1, $steps[3]['dropoff_count']);
        $this->assertEquals(33.3, $steps[3]['dropoff_rate']);
    }
}
