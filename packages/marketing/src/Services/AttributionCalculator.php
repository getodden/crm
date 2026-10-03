<?php

declare(strict_types=1);

namespace Odden\Marketing\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Odden\Marketing\Enums\AttributionModel;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\FormSubmission;

/**
 * Multi-touch attribution: finds the marketing touches of a set of contacts and splits a deal's
 * credit across them according to an AttributionModel.
 *
 * A touch is a campaign recipient's first open or click, or a form submission whose
 * `utm_campaign` matches a campaign (compared as slugs, the same way UTM auto-tagging builds them).
 * The credit for a deal is shared across the touches of all the deal's contacts, in time order,
 * so the weights of a deal always add up to 1.
 */
class AttributionCalculator
{
    /**
     * Touches of the given contacts, oldest first.
     *
     * @param  Collection<int, int>|list<int>  $contactIds
     * @return list<array{campaign_id: int, contact_id: int, at: CarbonInterface, type: 'email'|'form'}>
     */
    public function touchesFor(Collection|array $contactIds): array
    {
        $contactIds = collect($contactIds)->filter()->unique()->values();
        if ($contactIds->isEmpty()) {
            return [];
        }

        $touches = [];

        CampaignRecipient::query()
            ->whereIn('contact_id', $contactIds)
            ->where(function ($query): void {
                $query->whereNotNull('opened_at')->orWhereNotNull('clicked_at');
            })
            ->get(['campaign_id', 'contact_id', 'opened_at', 'clicked_at'])
            ->each(function (CampaignRecipient $recipient) use (&$touches): void {
                $times = array_filter([$recipient->opened_at, $recipient->clicked_at]);
                $first = collect($times)->sort()->first();

                $touches[] = ['campaign_id' => (int) $recipient->campaign_id, 'contact_id' => (int) $recipient->contact_id, 'at' => $first, 'type' => 'email'];
            });

        $campaignBySlug = Campaign::query()->get()->mapWithKeys(fn (Campaign $campaign): array => [$campaign->utmCampaignSlug() => $campaign->id]);

        FormSubmission::query()
            ->whereIn('contact_id', $contactIds)
            ->whereNotNull('utm_campaign')
            ->get(['contact_id', 'utm_campaign', 'created_at'])
            ->each(function (FormSubmission $submission) use (&$touches, $campaignBySlug): void {
                $campaignId = $campaignBySlug->get(Str::slug((string) $submission->utm_campaign));

                if ($campaignId !== null) {
                    $touches[] = ['campaign_id' => (int) $campaignId, 'contact_id' => (int) $submission->contact_id, 'at' => $submission->created_at, 'type' => 'form'];
                }
            });

        usort($touches, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        return $touches;
    }

    /**
     * The share of credit each touch gets (same order as $touches, adding up to 1).
     *
     * - first touch / last touch: all to the first / last touch
     * - linear: equal shares
     * - U-shaped: 40% first, 40% last, 20% shared by the touches between (50/50 with two touches)
     * - W-shaped: 30% first, 30% the conversion touch (the first form submission between them, else
     *   the middle touch), 30% last, 10% shared by the rest; with nothing left the 10% is shared by the three
     * - time decay: each touch is worth half as much for every `time_decay_half_life_days` (default 7)
     *   before the last touch, so recent touches count most
     *
     * @param  list<array{campaign_id: int, contact_id: int, at: CarbonInterface, type: 'email'|'form'}>  $touches
     * @return list<float>
     */
    public function weights(array $touches, AttributionModel $model): array
    {
        $count = count($touches);

        if ($count === 0) {
            return [];
        }

        if ($count === 1) {
            return [1.0];
        }

        $weights = array_fill(0, $count, 0.0);

        switch ($model) {
            case AttributionModel::FirstTouch:
                $weights[0] = 1.0;
                break;

            case AttributionModel::LastTouch:
                $weights[$count - 1] = 1.0;
                break;

            case AttributionModel::Linear:
                $weights = array_fill(0, $count, 1 / $count);
                break;

            case AttributionModel::UShaped:
                if ($count === 2) {
                    $weights = [0.5, 0.5];
                    break;
                }

                $weights = array_fill(0, $count, 0.2 / ($count - 2));
                $weights[0] = 0.4;
                $weights[$count - 1] = 0.4;
                break;

            case AttributionModel::WShaped:
                if ($count === 2) {
                    $weights = [0.5, 0.5];
                    break;
                }

                $middle = $this->conversionIndex($touches);
                $anchors = [0, $middle, $count - 1];
                $rest = array_values(array_diff(range(0, $count - 1), $anchors));

                foreach ($anchors as $anchor) {
                    $weights[$anchor] = $rest === [] ? 1 / 3 : 0.3;
                }

                foreach ($rest as $index) {
                    $weights[$index] = 0.1 / count($rest);
                }
                break;

            case AttributionModel::TimeDecay:
                $halfLifeDays = max(0.1, (float) config('odden-marketing.attribution.time_decay_half_life_days', 7));
                $last = $touches[$count - 1]['at'];
                $raw = array_map(
                    fn (array $touch): float => 0.5 ** (max(0.0, (float) $touch['at']->diffInSeconds($last, true)) / 86400 / $halfLifeDays),
                    $touches
                );
                $total = array_sum($raw);
                $weights = array_map(fn (float $weight): float => $weight / $total, $raw);
                break;
        }

        return $weights;
    }

    /**
     * The share of a deal's credit that goes to one campaign: the weights of that campaign's touches.
     *
     * @param  list<array{campaign_id: int, contact_id: int, at: CarbonInterface, type: 'email'|'form'}>  $touches
     */
    public function campaignCredit(array $touches, AttributionModel $model, int $campaignId): float
    {
        $weights = $this->weights($touches, $model);
        $credit = 0.0;

        foreach ($touches as $index => $touch) {
            if ($touch['campaign_id'] === $campaignId) {
                $credit += $weights[$index];
            }
        }

        return $credit;
    }

    /**
     * The touch that converted the lead for the W-shaped model: the first form submission strictly
     * between the first and last touch, otherwise the middle touch.
     *
     * @param  list<array{campaign_id: int, contact_id: int, at: CarbonInterface, type: 'email'|'form'}>  $touches
     */
    private function conversionIndex(array $touches): int
    {
        $last = count($touches) - 1;

        for ($index = 1; $index < $last; $index++) {
            if ($touches[$index]['type'] === 'form') {
                return $index;
            }
        }

        return intdiv($last, 2) ?: 1;
    }
}
