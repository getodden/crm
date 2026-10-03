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
use Odden\Marketing\Services\AttributionCalculator;
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

        // With a single touch, every model gives that touch all of the credit.
        $uShaped = $action->execute($campaign, AttributionModel::UShaped);
        $this->assertSame('u_shaped', $uShaped['attribution_model']);
        $this->assertEquals(100000.00, $uShaped['attributed_won_revenue']);

        $timeDecay = $action->execute($campaign, AttributionModel::TimeDecay);
        $this->assertSame('time_decay', $timeDecay['attribution_model']);
        $this->assertEquals(100000.00, $timeDecay['attributed_won_revenue']);
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
            'opened_at' => now()->subDay(),
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

        // A deal's credit is split across its touches and adds up to the whole deal, whatever the model.
        $uMetrics = $action->execute(AttributionModel::UShaped);
        $this->assertSame('u_shaped', $uMetrics['attribution_model']);
        $this->assertEquals(50000.00, $uMetrics['total_closed_won_revenue']);
        $this->assertEquals(50000.00, $uMetrics['attributed_closed_won_revenue']);
        $this->assertEquals(50000.00, $uMetrics['top_campaigns'][0]['attributed_won_revenue']);

        $wMetrics = $action->execute(AttributionModel::WShaped);
        $this->assertSame('w_shaped', $wMetrics['attribution_model']);
        $this->assertEquals(50000.00, $wMetrics['attributed_closed_won_revenue']);

        // Without a model nothing is weighted, and the per-campaign attributed figure is left out.
        $unweighted = $action->execute();
        $this->assertEquals(50000.00, $unweighted['attributed_closed_won_revenue']);
        $this->assertArrayNotHasKey('attributed_won_revenue', $unweighted['top_campaigns'][0]);
    }

    public function test_u_shaped_attribution_weights_each_touch_40_20_40(): void
    {
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

    /**
     * A won deal worth $100,000 for a contact, so tests only describe the touches.
     */
    private function wonDealFor(Contact $contact, bool $dealIsParent = false): Deal
    {
        $pipeline = Pipeline::create(['name' => 'P'.$contact->id, 'code' => 'p'.$contact->id]);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Closed Won', 'code' => 'won'.$contact->id, 'sort_order' => 1]);
        $deal = Deal::create(['name' => 'Deal '.$contact->id, 'amount' => 100000.00, 'status' => DealStatus::Won, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id]);

        $dealIsParent ? $deal->associateWith($contact) : $contact->associateWith($deal);

        return $deal;
    }

    private function campaignNamed(string $name, array $attributes = []): Campaign
    {
        return Campaign::create([...['name' => $name, 'subject' => $name, 'sender_name' => 'Odden', 'sender_email' => 'news@odden.test'], ...$attributes]);
    }

    public function test_w_shaped_attribution_weights_first_conversion_and_last_touch_30_30_30_and_10_for_the_rest(): void
    {
        $contact = Contact::create(['first_name' => 'Hedy', 'last_name' => 'Lamarr', 'email' => 'hedy@spread.test']);
        $this->wonDealFor($contact);

        $campaigns = [];
        foreach (['first', 'second', 'convert', 'fourth', 'last'] as $i => $name) {
            $campaigns[$name] = $this->campaignNamed("W {$name}");
        }

        $at = fn (int $daysAgo) => now()->subDays($daysAgo);
        foreach ([['first', 40], ['second', 30], ['fourth', 10], ['last', 2]] as $i => [$name, $daysAgo]) {
            CampaignRecipient::create(['campaign_id' => $campaigns[$name]->id, 'contact_id' => $contact->id, 'email' => $contact->email, 'tracking_token' => "w_{$i}", 'unsubscribe_token' => "wu_{$i}", 'opened_at' => $at($daysAgo)]);
        }

        // The form submission that converted the lead sits between the first and last touch.
        $form = MarketingForm::create(['title' => 'Convert', 'slug' => 'convert', 'fields_schema' => []]);
        $submission = FormSubmission::create(['form_id' => $form->id, 'contact_id' => $contact->id, 'form_data' => [], 'utm_campaign' => $campaigns['convert']->utmCampaignSlug()]);
        $submission->forceFill(['created_at' => $at(20)])->saveQuietly();

        $action = new GetCampaignAttributionAction;
        $credit = fn (string $name) => $action->execute($campaigns[$name], AttributionModel::WShaped)['attributed_won_revenue'];

        $this->assertEqualsWithDelta(30000.00, $credit('first'), 0.01);
        $this->assertEqualsWithDelta(30000.00, $credit('convert'), 0.01);
        $this->assertEqualsWithDelta(30000.00, $credit('last'), 0.01);
        $this->assertEqualsWithDelta(5000.00, $credit('second'), 0.01);
        $this->assertEqualsWithDelta(5000.00, $credit('fourth'), 0.01);
    }

    public function test_attribution_weights_always_add_up_to_one(): void
    {
        $calculator = app(AttributionCalculator::class);

        foreach ([1, 2, 3, 4, 7] as $count) {
            $touches = [];
            for ($i = 0; $i < $count; $i++) {
                $touches[] = ['campaign_id' => $i + 1, 'contact_id' => 1, 'at' => now()->subDays($count - $i), 'type' => $i === 1 ? 'form' : 'email'];
            }

            foreach (AttributionModel::cases() as $model) {
                $this->assertEqualsWithDelta(1.0, array_sum($calculator->weights($touches, $model)), 1e-9, "{$model->value} with {$count} touches");
            }
        }
    }

    public function test_form_submissions_match_the_campaign_utm_the_same_way_auto_tagging_builds_it(): void
    {
        $contact = Contact::create(['first_name' => 'Ken', 'last_name' => 'Thompson', 'email' => 'ken@unix.test']);
        $this->wonDealFor($contact);

        // The campaign's UTM value differs from its name, and the submission spells it differently.
        $campaign = $this->campaignNamed('Spring Launch Blast', ['utm_campaign' => 'Spring Sale 2026']);
        $this->assertSame('spring-sale-2026', $campaign->utmCampaignSlug());

        $form = MarketingForm::create(['title' => 'Spring', 'slug' => 'spring', 'fields_schema' => []]);
        FormSubmission::create(['form_id' => $form->id, 'contact_id' => $contact->id, 'form_data' => [], 'utm_campaign' => 'Spring  Sale 2026']);

        $result = (new GetCampaignAttributionAction)->execute($campaign, AttributionModel::FirstTouch);

        $this->assertSame(1, $result['leads_count']);
        $this->assertEquals(100000.00, $result['attributed_won_revenue']);
    }

    public function test_deals_count_whichever_side_of_the_association_they_are_on(): void
    {
        $campaign = $this->campaignNamed('Both Directions', ['delivered_count' => 2]);

        foreach ([[false, 'contact-first@dir.test'], [true, 'deal-first@dir.test']] as $i => [$dealIsParent, $email]) {
            $contact = Contact::create(['first_name' => 'Dir', 'last_name' => (string) $i, 'email' => $email]);
            $this->wonDealFor($contact, $dealIsParent);
            CampaignRecipient::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'email' => $email, 'tracking_token' => "dir_{$i}", 'unsubscribe_token' => "diru_{$i}", 'opened_at' => now()->subDay()]);
        }

        $metrics = (new CalculateClosedLoopMetricsAction)->execute(AttributionModel::Linear);

        $this->assertEquals(200000.00, $metrics['top_campaigns'][0]['won_revenue']);
        $this->assertEquals(200000.00, $metrics['top_campaigns'][0]['pipeline_influenced']);
        $this->assertEquals(200000.00, $metrics['top_campaigns'][0]['attributed_won_revenue']);
    }
}
