<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Str;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\AttributionModel;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\FormSubmission;
use Odden\Marketing\Services\AttributionCalculator;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;

class GetCampaignAttributionAction
{
    /**
     * Compute marketing attribution performance: leads generated, deals influenced, and revenue pipeline
     * weighted by the requested attribution model.
     *
     * @return array{
     *     campaign_name: string,
     *     attribution_model: string,
     *     leads_count: int,
     *     engaged_contacts_count: int,
     *     deals_count: int,
     *     budget: float|null,
     *     actual_cost: float,
     *     net_profit: float,
     *     roi_percentage: float,
     *     cost_per_lead: float,
     *     pipeline_value: float,
     *     won_revenue: float,
     *     attributed_pipeline_value: float,
     *     attributed_won_revenue: float
     * }
     */
    public function execute(Campaign $campaign, AttributionModel $model = AttributionModel::Linear): array
    {
        $calculator = app(AttributionCalculator::class);

        // 1. Leads generated via form submissions whose utm_campaign is this campaign's slug
        // (the same slug UTM auto-tagging puts in the campaign's links).
        $campaignSlug = $campaign->utmCampaignSlug();
        $formContactIds = FormSubmission::query()
            ->whereNotNull('utm_campaign')
            ->whereNotNull('contact_id')
            ->get(['contact_id', 'utm_campaign'])
            ->filter(fn (FormSubmission $submission): bool => Str::slug((string) $submission->utm_campaign) === $campaignSlug)
            ->pluck('contact_id')
            ->unique()
            ->values()
            ->all();

        // 2. Engaged campaign recipients (opened or clicked)
        $engagedContactIds = $campaign->recipients()
            ->where(function ($q): void {
                $q->whereNotNull('opened_at')->orWhereNotNull('clicked_at');
            })
            ->pluck('contact_id')
            ->filter()
            ->unique()
            ->all();

        $allInfluencedContactIds = array_values(array_unique(array_merge($formContactIds, $engagedContactIds)));

        $dealsCount = 0;
        $rawPipelineValue = 0.0;
        $rawWonRevenue = 0.0;
        $attributedPipeline = 0.0;
        $attributedWon = 0.0;

        if (! empty($allInfluencedContactIds) && class_exists(Deal::class)) {
            // Deals associated with the influenced contacts, in either direction
            $contacts = Contact::query()->whereIn('id', $allInfluencedContactIds)->get();

            $deals = [];
            foreach ($contacts as $contact) {
                foreach ($contact->getAssociated(Deal::class) as $deal) {
                    $deals[$deal->id] = $deal;
                }
            }

            $dealsCount = count($deals);
            foreach ($deals as $deal) {
                $amount = (float) $deal->amount;
                if ($deal->status !== DealStatus::Won && $deal->status !== DealStatus::Open) {
                    continue;
                }

                // This campaign's share of the deal: its touches among every touch of the deal's contacts.
                $dealContactIds = $deal->getAssociated(Contact::class)->pluck('id');
                $credit = $calculator->campaignCredit($calculator->touchesFor($dealContactIds, $deal->status === DealStatus::Won ? $deal->closed_at : null), $model, $campaign->id);

                if ($deal->status === DealStatus::Won) {
                    $rawWonRevenue += $amount;
                    $attributedWon += $amount * $credit;
                } else {
                    $rawPipelineValue += $amount;
                    $attributedPipeline += $amount * $credit;
                }
            }
        }

        $attributedPipeline = round($attributedPipeline, 2);
        $attributedWon = round($attributedWon, 2);

        $cost = (float) ($campaign->actual_spend ?: $campaign->actual_cost ?: 0.0);
        $netProfit = $attributedWon - $cost;
        $roiPercentage = $cost > 0 ? round(($netProfit / $cost) * 100, 2) : 0.0;
        $costPerLead = count($formContactIds) > 0 ? round($cost / count($formContactIds), 2) : 0.0;

        return [
            'campaign_name' => $campaign->name,
            'attribution_model' => $model->value,
            'leads_count' => count($formContactIds),
            'engaged_contacts_count' => count($engagedContactIds),
            'deals_count' => $dealsCount,
            'budget' => $campaign->budget !== null ? (float) $campaign->budget : null,
            'actual_cost' => $cost,
            'net_profit' => round($netProfit, 2),
            'roi_percentage' => $roiPercentage,
            'cost_per_lead' => $costPerLead,
            'pipeline_value' => round($rawPipelineValue, 2),
            'won_revenue' => round($rawWonRevenue, 2),
            'attributed_pipeline_value' => $attributedPipeline,
            'attributed_won_revenue' => $attributedWon,
        ];
    }
}
