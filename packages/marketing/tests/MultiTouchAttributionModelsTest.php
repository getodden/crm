<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\CalculateClosedLoopMetricsAction;
use Odden\Marketing\Actions\GetCampaignAttributionAction;
use Odden\Marketing\Enums\AttributionModel;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Models\MarketingForm;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class MultiTouchAttributionModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_u_shaped_and_time_decay_attribution_models(): void
    {
        $contact = Contact::create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'grace@navy.mil',
        ]);

        $campaign = Campaign::create([
            'name' => 'Compiler Revolution',
            'subject' => 'The Future of COBOL',
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
            'budget' => 10000.00,
            'actual_spend' => 5000.00,
        ]);

        $form = MarketingForm::create([
            'title' => 'Compiler Whitepaper',
            'slug' => 'compiler-whitepaper',
            'fields_schema' => [],
        ]);

        FormSubmission::create([
            'form_id' => $form->id,
            'contact_id' => $contact->id,
            'form_data' => ['email' => $contact->email],
            'utm_campaign' => 'compiler-revolution',
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'tok_hop_1',
            'unsubscribe_token' => 'unsub_hop_1',
            'opened_at' => now()->subDays(2),
        ]);

        $pipeline = Pipeline::create(['name' => 'Govt Tech', 'code' => 'govt_tech']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed_won', 'sort_order' => 1]);

        $deal = Deal::create([
            'name' => 'DoD Compiler Deployment',
            'amount' => 100000.00,
            'status' => DealStatus::Won,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);
        $contact->associateWith($deal);

        $action = new GetCampaignAttributionAction;

        // U-Shaped (80% weighted credit)
        $uShaped = $action->execute($campaign, AttributionModel::UShaped);
        $this->assertSame('u_shaped', $uShaped['attribution_model']);
        $this->assertEquals(80000.00, $uShaped['attributed_won_revenue']);

        // Time-Decay (65% weighted credit)
        $timeDecay = $action->execute($campaign, AttributionModel::TimeDecay);
        $this->assertSame('time_decay', $timeDecay['attribution_model']);
        $this->assertEquals(65000.00, $timeDecay['attributed_won_revenue']);
    }

    public function test_calculate_closed_loop_metrics_with_u_shaped_and_w_shaped_models(): void
    {
        $contact = Contact::create([
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'email' => 'turing@bletchley.uk',
        ]);

        $campaign = Campaign::create([
            'name' => 'Enigma Analytics Briefing',
            'subject' => 'Decoding Data',
            'sender_name' => 'Odden',
            'sender_email' => 'intel@odden.test',
            'actual_spend' => 10000.00,
            'delivered_count' => 1,
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'tok_tur_1',
            'unsubscribe_token' => 'unsub_tur_1',
        ]);

        $pipeline = Pipeline::create(['name' => 'Defense', 'code' => 'defense']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed_won', 'sort_order' => 1]);

        $deal = Deal::create([
            'name' => 'Bletchley Park Analysis Contract',
            'amount' => 50000.00,
            'status' => DealStatus::Won,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);

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

        // U-Shaped (80% attribution)
        $uMetrics = $action->execute(AttributionModel::UShaped);
        $this->assertSame('u_shaped', $uMetrics['attribution_model']);
        $this->assertEquals(50000.00, $uMetrics['total_closed_won_revenue']);
        $this->assertEquals(40000.00, $uMetrics['attributed_closed_won_revenue']); // 50000 * 0.80

        // W-Shaped (70% attribution)
        $wMetrics = $action->execute(AttributionModel::WShaped);
        $this->assertSame('w_shaped', $wMetrics['attribution_model']);
        $this->assertEquals(35000.00, $wMetrics['attributed_closed_won_revenue']); // 50000 * 0.70
    }

    public function test_u_shaped_attribution_weights_each_touch_40_20_40(): void
    {
        $this->markTestIncomplete('Attribution models apply flat multipliers, not per-touch weights; fixed by #26.');

        $contact = Contact::create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@analytical.test']);

        $make = fn (string $name) => Campaign::create([
            'name' => $name,
            'subject' => $name,
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
        ]);
        $first = $make('First Touch Campaign');
        $middle = $make('Middle Touch Campaign');
        $last = $make('Last Touch Campaign');

        foreach ([[$first, 30], [$middle, 15], [$last, 1]] as $i => [$campaign, $daysAgo]) {
            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'email' => $contact->email,
                'tracking_token' => 'tok_ada_'.$i,
                'unsubscribe_token' => 'unsub_ada_'.$i,
                'opened_at' => now()->subDays($daysAgo),
            ]);
        }

        $pipeline = Pipeline::create(['name' => 'Analytical', 'code' => 'analytical']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed_won', 'sort_order' => 1]);
        $deal = Deal::create([
            'name' => 'Engine Deal',
            'amount' => 100000.00,
            'status' => DealStatus::Won,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);
        $contact->associateWith($deal);

        $action = new GetCampaignAttributionAction;

        // U-shaped: 40% first touch, 20% shared across middle touches, 40% last touch.
        $this->assertEquals(40000.00, $action->execute($first, AttributionModel::UShaped)['attributed_won_revenue']);
        $this->assertEquals(20000.00, $action->execute($middle, AttributionModel::UShaped)['attributed_won_revenue']);
        $this->assertEquals(40000.00, $action->execute($last, AttributionModel::UShaped)['attributed_won_revenue']);
    }

    public function test_time_decay_attribution_favors_more_recent_touches(): void
    {
        $this->markTestIncomplete('TimeDecay applies a flat 0.65 regardless of touch recency; fixed by #26.');

        $contact = Contact::create(['first_name' => 'Edsger', 'last_name' => 'Dijkstra', 'email' => 'edsger@algo.test']);

        $make = fn (string $name) => Campaign::create([
            'name' => $name,
            'subject' => $name,
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
        ]);
        $old = $make('Old Campaign');
        $recent = $make('Recent Campaign');

        foreach ([[$old, 90], [$recent, 1]] as $i => [$campaign, $daysAgo]) {
            CampaignRecipient::create([
                'campaign_id' => $campaign->id,
                'contact_id' => $contact->id,
                'email' => $contact->email,
                'tracking_token' => 'tok_dij_'.$i,
                'unsubscribe_token' => 'unsub_dij_'.$i,
                'opened_at' => now()->subDays($daysAgo),
            ]);
        }

        $pipeline = Pipeline::create(['name' => 'Algo', 'code' => 'algo']);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'closed_won', 'sort_order' => 1]);
        $deal = Deal::create([
            'name' => 'Shortest Path Deal',
            'amount' => 100000.00,
            'status' => DealStatus::Won,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
        ]);
        $contact->associateWith($deal);

        $action = new GetCampaignAttributionAction;
        $oldCredit = $action->execute($old, AttributionModel::TimeDecay)['attributed_won_revenue'];
        $recentCredit = $action->execute($recent, AttributionModel::TimeDecay)['attributed_won_revenue'];

        $this->assertGreaterThan($oldCredit, $recentCredit);
        // Credit across touches is split, not multiplied per campaign.
        $this->assertEqualsWithDelta(100000.00, $oldCredit + $recentCredit, 0.01);
    }
}
