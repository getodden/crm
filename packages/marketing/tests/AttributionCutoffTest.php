<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\GetCampaignAttributionAction;
use Odden\Marketing\Enums\AttributionModel;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Services\AttributionCalculator;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class AttributionCutoffTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{Campaign, Campaign, Deal}
     */
    private function wonDealWithTouches(): array
    {
        $contact = Contact::create(['first_name' => 'Buyer', 'email' => 'buyer@example.com']);
        $before = Campaign::create(['name' => 'Spring launch', 'subject' => 'A', 'sender_name' => 'O', 'sender_email' => 'o@odden.test']);
        $after = Campaign::create(['name' => 'Autumn newsletter', 'subject' => 'B', 'sender_name' => 'O', 'sender_email' => 'o@odden.test']);

        $before->recipients()->create(['contact_id' => $contact->id, 'email' => $contact->email, 'status' => RecipientStatus::Sent, 'opened_at' => now()->subDays(30)]);
        // Opened months after the deal was won.
        $after->recipients()->create(['contact_id' => $contact->id, 'email' => $contact->email, 'status' => RecipientStatus::Sent, 'opened_at' => now()->subDays(1)]);

        $pipeline = Pipeline::create(['name' => 'Sales', 'code' => 'sales', 'is_default' => true]);
        $stage = PipelineStage::create(['pipeline_id' => $pipeline->id, 'name' => 'Won', 'code' => 'won', 'probability' => 100, 'sort_order' => 1, 'is_closed_won' => true]);
        $deal = Deal::create(['name' => 'Contract', 'amount' => 10000, 'pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'status' => DealStatus::Won, 'closed_at' => now()->subDays(20)]);
        $contact->associateWith($deal);

        return [$before, $after, $deal];
    }

    public function test_touches_after_a_won_deal_closed_are_left_out(): void
    {
        [$before, , $deal] = $this->wonDealWithTouches();
        $calculator = new AttributionCalculator;
        $contactIds = $deal->getAssociated(Contact::class)->pluck('id');

        $this->assertCount(2, $calculator->touchesFor($contactIds));

        $touches = $calculator->touchesFor($contactIds, $deal->closed_at);
        $this->assertCount(1, $touches);
        $this->assertSame($before->id, $touches[0]['campaign_id']);
    }

    public function test_a_campaign_opened_only_after_the_win_gets_no_revenue_credit(): void
    {
        [$before, $after] = $this->wonDealWithTouches();

        $late = (new GetCampaignAttributionAction)->execute($after, AttributionModel::LastTouch);
        $early = (new GetCampaignAttributionAction)->execute($before, AttributionModel::LastTouch);

        $this->assertSame(0.0, $late['attributed_won_revenue']);
        $this->assertSame(10000.0, $early['attributed_won_revenue']);
    }
}
