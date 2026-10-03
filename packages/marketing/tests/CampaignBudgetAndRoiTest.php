<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\CalculateClosedLoopMetricsAction;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class CampaignBudgetAndRoiTest extends TestCase
{
    use RefreshDatabase;

    public function test_computes_marketing_spend_blended_cac_and_roi(): void
    {
        $contact = Contact::create([
            'first_name' => 'Enterprise',
            'last_name' => 'Buyer',
            'email' => 'buyer@megacorp.test',
        ]);

        $campaign = Campaign::create([
            'name' => 'Q4 Enterprise Webinar',
            'subject' => 'Live Demo',
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
            'budget' => 5000.00,
            'actual_spend' => 2000.00,
            'delivered_count' => 100,
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'tok_roi_1',
            'unsubscribe_token' => 'unsub_roi_1',
        ]);

        $pipeline = Pipeline::create(['name' => 'Sales Funnel', 'code' => 'sales_funnel']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed_won', 'sort_order' => 1]);

        $deal = Deal::create([
            'name' => 'MegaCorp Enterprise License',
            'amount' => 10000.00,
            'status' => DealStatus::Won,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

        // Associate contact with deal
        $associationsTable = config('odden-core.tables.associations', 'odden_associations');
        DB::table($associationsTable)->insert([
            'parent_type' => (new Contact)->getMorphClass(),
            'parent_id' => $contact->id,
            'child_type' => (new Deal)->getMorphClass(),
            'child_id' => $deal->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $action = new CalculateClosedLoopMetricsAction;
        $metrics = $action->execute();

        // 1. Spend: $2,000.00
        $this->assertEquals(2000.00, $metrics['total_marketing_spend']);

        // 2. Won Revenue: $10,000.00
        $this->assertEquals(10000.00, $metrics['total_closed_won_revenue']);

        // 3. Blended CAC: $2,000 spend / 1 won deal = $2,000.00
        $this->assertEquals(2000.00, $metrics['blended_cac']);

        // 4. Cost Per Lead (CPL): $2,000 spend / 1 lead = $2,000.00
        $this->assertEquals(2000.00, $metrics['cost_per_lead']);

        // 5. Marketing ROI %: ((10,000 - 2,000) / 2,000) * 100 = 400.0%
        $this->assertEquals(400.0, $metrics['marketing_roi_percentage']);

        // 6. Top campaign ROI
        $this->assertNotEmpty($metrics['top_campaigns']);
        $this->assertSame('Q4 Enterprise Webinar', $metrics['top_campaigns'][0]['name']);
        $this->assertEquals(2000.00, $metrics['top_campaigns'][0]['spend']);
        $this->assertEquals(400.0, $metrics['top_campaigns'][0]['roi_percentage']);
    }
}
