<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Facades\Log;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Models\Campaign;

class EvaluateAbTestWinnerAction
{
    /**
     * Evaluate A/B test results, pick the winning variant based on engagement,
     * and roll out the winning variant to all remaining staged recipients.
     *
     * `tie` is true when both variants scored the same; the control (variant A) is rolled out
     * then, and callers should surface that rather than present it as a win.
     *
     * @return array{winner: string, metric: string, variant_a_score: float, variant_b_score: float, remaining_sent: int, tie: bool}
     */
    public function execute(Campaign $campaign): array
    {
        if (! $campaign->is_ab_test || $campaign->ab_winner_variant !== null) {
            return [
                'winner' => $campaign->ab_winner_variant ?? 'A',
                'metric' => $campaign->ab_winning_metric,
                'variant_a_score' => 0.0,
                'variant_b_score' => 0.0,
                'remaining_sent' => 0,
                'tie' => false,
            ];
        }

        // Metrics for Variant A
        $sentA = $campaign->recipients()->where('variant', 'A')->whereNotNull('sent_at')->count();
        $opensA = $campaign->recipients()->where('variant', 'A')->whereNotNull('opened_at')->count();
        $clicksA = $campaign->recipients()->where('variant', 'A')->whereNotNull('clicked_at')->count();

        $openRateA = $sentA > 0 ? ($opensA / $sentA) * 100 : 0.0;
        $clickRateA = $sentA > 0 ? ($clicksA / $sentA) * 100 : 0.0;

        // Metrics for Variant B
        $sentB = $campaign->recipients()->where('variant', 'B')->whereNotNull('sent_at')->count();
        $opensB = $campaign->recipients()->where('variant', 'B')->whereNotNull('opened_at')->count();
        $clicksB = $campaign->recipients()->where('variant', 'B')->whereNotNull('clicked_at')->count();

        $openRateB = $sentB > 0 ? ($opensB / $sentB) * 100 : 0.0;
        $clickRateB = $sentB > 0 ? ($clicksB / $sentB) * 100 : 0.0;

        $isClickMetric = $campaign->ab_winning_metric === 'click_rate';
        $scoreA = $isClickMetric ? $clickRateA : $openRateA;
        $scoreB = $isClickMetric ? $clickRateB : $openRateB;

        $tie = abs($scoreA - $scoreB) < 0.0001;
        $winner = $scoreB > $scoreA ? 'B' : 'A';

        if ($tie) {
            Log::warning("A/B test for campaign #{$campaign->id} ended in a tie ({$scoreA}%); rolling out variant A.");
        }

        // Roll out the winner to the staged audience (recipients without a variant). Recipients
        // that already hold a test variant are still waiting for their local send time.
        $pendingRecipients = $campaign->recipients()
            ->where('status', RecipientStatus::Pending->value)
            ->whereNull('variant')
            ->with('contact')
            ->get();

        $delivery = app(DeliverCampaignMessageAction::class);
        $remainingSent = 0;
        $useTimezoneSending = $campaign->send_in_recipient_timezone || $campaign->send_by_timezone || $campaign->use_sto;

        foreach ($pendingRecipients as $recipient) {
            // Honor the recipient's local send time, sending the winner when it arrives.
            if ($useTimezoneSending && $recipient->contact !== null) {
                $targetTime = $recipient->scheduled_send_at ?? $campaign->calculateScheduledTimeForContact($recipient->contact);
                if ($targetTime->isFuture() && now()->diffInMinutes($targetTime) > 5) {
                    $recipient->update(['variant' => $winner, 'scheduled_send_at' => $targetTime]);

                    continue;
                }
            }

            $outcome = $delivery->execute(
                $campaign,
                $recipient,
                variant: $winner,
                activityTitle: "Marketing Campaign: {$campaign->name} (Winning Variant {$winner})",
            );

            if ($outcome === DeliverCampaignMessageAction::QUEUED) {
                $remainingSent++;
            }
        }

        // Recipients held for their local send time are still pending: the campaign stays Sending so the
        // scheduled sweep sends them the winner, and that sweep marks it Sent once none are left.
        $hasPending = $campaign->recipients()->where('status', RecipientStatus::Pending->value)->exists();

        $campaign->update([
            'ab_winner_variant' => $winner,
            'ab_test_evaluated_at' => now(),
            'status' => $hasPending ? CampaignStatus::Sending : CampaignStatus::Sent,
            'delivered_count' => $campaign->recipients()->whereNotNull('sent_at')->count(),
        ]);

        return [
            'winner' => $winner,
            'metric' => $campaign->ab_winning_metric,
            'variant_a_score' => round($scoreA, 2),
            'variant_b_score' => round($scoreB, 2),
            'remaining_sent' => $remainingSent,
            'tie' => $tie,
        ];
    }
}
