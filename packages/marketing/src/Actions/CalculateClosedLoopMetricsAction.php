<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\AttributionModel;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Services\AttributionCalculator;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;

class CalculateClosedLoopMetricsAction
{
    /**
     * Compute closed-loop marketing ROI, pipeline influence, and sales cycle velocity.
     *
     * @return array{
     *     total_influenced_pipeline: float,
     *     total_closed_won_revenue: float,
     *     total_marketing_spend: float,
     *     blended_cac: float,
     *     cost_per_lead: float,
     *     marketing_roi_percentage: float,
     *     won_deals_count: int,
     *     open_deals_count: int,
     *     marketing_win_rate: float,
     *     average_sales_cycle_days: float,
     *     top_campaigns: array<int, array{name: string, won_revenue: float, pipeline_influenced: float, spend: float, roi_percentage: float, attributed_won_revenue?: float}>,
     *     attribution_model: string|null,
     *     attributed_closed_won_revenue: float,
     *     attributed_pipeline: float,
     *     attributed_roi_percentage: float
     * }
     */
    public function execute(?AttributionModel $model = null): array
    {
        $calculator = app(AttributionCalculator::class);
        $totalSpend = (float) Campaign::query()->sum('actual_spend');
        if ($totalSpend === 0.0) {
            $totalSpend = (float) Campaign::query()->sum('actual_cost');
        }

        // Check if Sales package models exist
        if (! class_exists(Deal::class)) {
            return [
                'total_influenced_pipeline' => 0.0,
                'total_closed_won_revenue' => 0.0,
                'total_marketing_spend' => $totalSpend,
                'blended_cac' => 0.0,
                'cost_per_lead' => 0.0,
                'marketing_roi_percentage' => 0.0,
                'won_deals_count' => 0,
                'open_deals_count' => 0,
                'marketing_win_rate' => 0.0,
                'average_sales_cycle_days' => 0.0,
                'top_campaigns' => [],
                'attribution_model' => $model?->value,
                'attributed_closed_won_revenue' => 0.0,
                'attributed_pipeline' => 0.0,
                'attributed_roi_percentage' => 0.0,
            ];
        }

        // 1. Identify all marketing-engaged Contact IDs (campaign recipients or form submissions)
        $campaignContactIds = CampaignRecipient::query()
            ->whereNotNull('contact_id')
            ->distinct()
            ->pluck('contact_id');

        $formContactIds = FormSubmission::query()
            ->whereNotNull('contact_id')
            ->distinct()
            ->pluck('contact_id');

        $marketingContactIds = $campaignContactIds->merge($formContactIds)->unique()->values();

        if ($marketingContactIds->isEmpty()) {
            return [
                'total_influenced_pipeline' => 0.0,
                'total_closed_won_revenue' => 0.0,
                'total_marketing_spend' => $totalSpend,
                'blended_cac' => 0.0,
                'cost_per_lead' => 0.0,
                'marketing_roi_percentage' => 0.0,
                'won_deals_count' => 0,
                'open_deals_count' => 0,
                'marketing_win_rate' => 0.0,
                'average_sales_cycle_days' => 0.0,
                'top_campaigns' => [],
                'attribution_model' => $model?->value,
                'attributed_closed_won_revenue' => 0.0,
                'attributed_pipeline' => 0.0,
                'attributed_roi_percentage' => 0.0,
            ];
        }

        // 2. Query association table for deals connected to these contacts
        $associationsTable = config('odden-core.tables.associations', 'odden_associations');
        $contactMorph = (new Contact)->getMorphClass();
        $dealMorph = (new Deal)->getMorphClass();

        $influencedDealIds = DB::table($associationsTable)
            ->where('parent_type', $contactMorph)
            ->whereIn('parent_id', $marketingContactIds)
            ->where('child_type', $dealMorph)
            ->pluck('child_id')
            ->merge(
                DB::table($associationsTable)
                    ->where('child_type', $contactMorph)
                    ->whereIn('child_id', $marketingContactIds)
                    ->where('parent_type', $dealMorph)
                    ->pluck('parent_id')
            )
            ->unique()
            ->values();

        $totalLeads = $marketingContactIds->count();
        $costPerLead = $totalLeads > 0 ? round($totalSpend / $totalLeads, 2) : 0.0;

        if ($influencedDealIds->isEmpty()) {
            return [
                'total_influenced_pipeline' => 0.0,
                'total_closed_won_revenue' => 0.0,
                'total_marketing_spend' => $totalSpend,
                'blended_cac' => 0.0,
                'cost_per_lead' => $costPerLead,
                'marketing_roi_percentage' => 0.0,
                'won_deals_count' => 0,
                'open_deals_count' => 0,
                'marketing_win_rate' => 0.0,
                'average_sales_cycle_days' => 0.0,
                'top_campaigns' => [],
                'attribution_model' => $model?->value,
                'attributed_closed_won_revenue' => 0.0,
                'attributed_pipeline' => 0.0,
                'attributed_roi_percentage' => 0.0,
            ];
        }

        /** @var Collection<int, Deal> $influencedDeals */
        $influencedDeals = Deal::query()->whereIn('id', $influencedDealIds)->get();

        $totalInfluencedPipeline = (float) $influencedDeals->sum('amount');

        $wonDeals = $influencedDeals->filter(fn (Deal $d): bool => $d->status === DealStatus::Won);
        $lostDeals = $influencedDeals->filter(fn (Deal $d): bool => $d->status === DealStatus::Lost);
        $openDeals = $influencedDeals->filter(fn (Deal $d): bool => $d->status === DealStatus::Open);

        $totalClosedWonRevenue = (float) $wonDeals->sum('amount');
        $wonCount = $wonDeals->count();
        $lostCount = $lostDeals->count();
        $openCount = $openDeals->count();
        $closedTotal = $wonCount + $lostCount;

        $winRate = $closedTotal > 0 ? round(($wonCount / $closedTotal) * 100, 1) : 0.0;
        $blendedCac = $wonCount > 0 ? round($totalSpend / $wonCount, 2) : 0.0;
        $roiPercentage = $totalSpend > 0 ? round((($totalClosedWonRevenue - $totalSpend) / $totalSpend) * 100, 1) : 0.0;

        // 3. Compute Average Sales Cycle Days (Contact Created -> Deal Closed Won)
        $totalCycleDays = 0.0;
        $cycleCount = 0;

        foreach ($wonDeals as $deal) {
            $contactId = DB::table($associationsTable)
                ->where('parent_type', $dealMorph)
                ->where('parent_id', $deal->id)
                ->where('child_type', $contactMorph)
                ->value('child_id')
                ?? DB::table($associationsTable)
                    ->where('child_type', $dealMorph)
                    ->where('child_id', $deal->id)
                    ->where('parent_type', $contactMorph)
                    ->value('parent_id');

            /** @var Contact|null $primaryContact */
            $primaryContact = $contactId !== null ? Contact::query()->find($contactId) : null;

            if ($primaryContact !== null && $primaryContact->created_at !== null) {
                $closeDate = $deal->closed_at ?? $deal->updated_at ?? now();
                $days = $primaryContact->created_at->diffInDays($closeDate);
                $totalCycleDays += max(1.0, (float) $days);
                $cycleCount++;
            }
        }

        $avgSalesCycleDays = $cycleCount > 0 ? round($totalCycleDays / $cycleCount, 1) : 0.0;

        // 4. Top Campaigns by Influenced Revenue
        $topCampaigns = [];
        /** @var Collection<int, Campaign> $campaigns */
        $campaigns = Campaign::query()->where('delivered_count', '>', 0)->get();

        foreach ($campaigns as $camp) {
            $campContactIds = $camp->recipients()->whereNotNull('contact_id')->pluck('contact_id');
            if ($campContactIds->isEmpty()) {
                continue;
            }

            // Deals linked to the campaign's contacts, whichever side of the association the deal is on.
            $campDealIds = DB::table($associationsTable)
                ->where('parent_type', $contactMorph)
                ->whereIn('parent_id', $campContactIds)
                ->where('child_type', $dealMorph)
                ->pluck('child_id')
                ->merge(
                    DB::table($associationsTable)
                        ->where('child_type', $contactMorph)
                        ->whereIn('child_id', $campContactIds)
                        ->where('parent_type', $dealMorph)
                        ->pluck('parent_id')
                )
                ->unique()
                ->values();

            if ($campDealIds->isNotEmpty()) {
                $campDeals = Deal::query()->whereIn('id', $campDealIds)->get();
                $wonRev = (float) $campDeals->where('status', DealStatus::Won)->sum('amount');
                $pipe = (float) $campDeals->sum('amount');
                $campSpend = (float) ($camp->actual_spend ?: $camp->actual_cost ?: 0.0);
                $campRoi = $campSpend > 0 ? round((($wonRev - $campSpend) / $campSpend) * 100, 1) : 0.0;

                if ($pipe > 0) {
                    $entry = [
                        'name' => $camp->name,
                        'won_revenue' => $wonRev,
                        'pipeline_influenced' => $pipe,
                        'spend' => $campSpend,
                        'roi_percentage' => $campRoi,
                    ];

                    // With an attribution model, also show the campaign's weighted share of the won revenue.
                    if ($model !== null) {
                        $attributedShare = 0.0;
                        foreach ($campDeals->where('status', DealStatus::Won) as $campDeal) {
                            $touches = $calculator->touchesFor($this->contactIdsForDeal($campDeal, $associationsTable, $contactMorph, $dealMorph), $campDeal->closed_at);
                            $attributedShare += (float) $campDeal->amount * $calculator->campaignCredit($touches, $model, $camp->id);
                        }
                        $entry['attributed_won_revenue'] = round($attributedShare, 2);
                    }

                    $topCampaigns[] = $entry;
                }
            }
        }

        usort($topCampaigns, fn (array $a, array $b): int => $b['won_revenue'] <=> $a['won_revenue']);
        $topCampaigns = array_slice($topCampaigns, 0, 5);

        // Without a model nothing is weighted. With one, a deal's credit is split across its marketing
        // touches and always adds up to the whole deal, so the totals only count deals that have at
        // least one touch; how the credit divides between campaigns is in each campaign's own result.
        $attributedWonRevenue = $totalClosedWonRevenue;
        $attributedPipeline = $totalInfluencedPipeline;

        if ($model !== null) {
            $attributedWonRevenue = 0.0;
            $attributedPipeline = 0.0;

            foreach ($influencedDeals as $deal) {
                if ($calculator->touchesFor($this->contactIdsForDeal($deal, $associationsTable, $contactMorph, $dealMorph), $deal->status === DealStatus::Won ? $deal->closed_at : null) === []) {
                    continue;
                }

                if ($deal->status === DealStatus::Won) {
                    $attributedWonRevenue += (float) $deal->amount;
                    $attributedPipeline += (float) $deal->amount;
                } elseif ($deal->status === DealStatus::Open) {
                    $attributedPipeline += (float) $deal->amount;
                }
            }
        }

        $attributedWonRevenue = round($attributedWonRevenue, 2);
        $attributedPipeline = round($attributedPipeline, 2);
        $attributedRoi = $totalSpend > 0 ? round((($attributedWonRevenue - $totalSpend) / $totalSpend) * 100, 1) : 0.0;

        return [
            'total_influenced_pipeline' => $totalInfluencedPipeline,
            'total_closed_won_revenue' => $totalClosedWonRevenue,
            'total_marketing_spend' => $totalSpend,
            'blended_cac' => $blendedCac,
            'cost_per_lead' => $costPerLead,
            'marketing_roi_percentage' => $roiPercentage,
            'won_deals_count' => $wonCount,
            'open_deals_count' => $openCount,
            'marketing_win_rate' => $winRate,
            'average_sales_cycle_days' => $avgSalesCycleDays,
            'top_campaigns' => $topCampaigns,
            'attribution_model' => $model?->value,
            'attributed_closed_won_revenue' => $attributedWonRevenue,
            'attributed_pipeline' => $attributedPipeline,
            'attributed_roi_percentage' => $attributedRoi,
        ];
    }

    /**
     * Ids of the contacts linked to a deal, on either side of the association.
     *
     * @return Collection<int, int>
     */
    private function contactIdsForDeal(Deal $deal, string $associationsTable, string $contactMorph, string $dealMorph): Collection
    {
        return DB::table($associationsTable)
            ->where('parent_type', $dealMorph)
            ->where('parent_id', $deal->id)
            ->where('child_type', $contactMorph)
            ->pluck('child_id')
            ->merge(
                DB::table($associationsTable)
                    ->where('child_type', $dealMorph)
                    ->where('child_id', $deal->id)
                    ->where('parent_type', $contactMorph)
                    ->pluck('parent_id')
            )
            ->unique()
            ->values();
    }
}
