<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\LeadDecayLog;
use Odden\Marketing\Models\LeadScoreLog;

class DecayInactiveLeadScoresAction
{
    /**
     * Scan contacts with active lead scores and apply inactivity time-decay degradation,
     * demoting stale contacts across lifecycle stages.
     *
     * @return array{decayed_contacts_count: int, total_points_decayed: int}
     */
    public function execute(int $inactivityThresholdDays = 30, int $decayPointsPerPeriod = 5): array
    {
        /** @var Collection<int, Contact> $contacts */
        $contacts = Contact::query()
            ->where('lead_score', '>', 0)
            ->get();

        $decayedCount = 0;
        $totalPointsDecayed = 0;

        foreach ($contacts as $contact) {
            $lastActive = $contact->lead_score_updated_at
                ?? $contact->last_contacted_at
                ?? $contact->created_at
                ?? now();

            $daysInactive = (int) $lastActive->diffInDays(now());

            if ($daysInactive < $inactivityThresholdDays) {
                continue;
            }

            // Calculate degradation based on number of 30-day elapsed periods
            $periods = (int) floor($daysInactive / $inactivityThresholdDays);
            $pointsToDeduct = min($contact->lead_score, $periods * $decayPointsPerPeriod);

            if ($pointsToDeduct <= 0) {
                continue;
            }

            DB::transaction(function () use ($contact, $pointsToDeduct, $daysInactive): void {
                $oldScore = $contact->lead_score;
                $newScore = max(0, $oldScore - $pointsToDeduct);

                // Determine lifecycle stage degradation
                $stage = $contact->lifecycle_stage;
                $mqlThreshold = (int) config('odden-marketing.sales_handoff.mql_score_threshold', 50);
                $sqlThreshold = (int) config('odden-marketing.sales_handoff.sql_score_threshold', 100);

                if ($newScore < $mqlThreshold && $stage === LifecycleStage::MarketingQualifiedLead) {
                    $stage = LifecycleStage::Lead;
                } elseif ($newScore < $sqlThreshold && $stage === LifecycleStage::SalesQualifiedLead) {
                    $stage = LifecycleStage::MarketingQualifiedLead;
                } elseif ($newScore === 0 && $stage === LifecycleStage::Lead) {
                    $stage = LifecycleStage::Subscriber;
                }

                $contact->update([
                    'lead_score' => $newScore,
                    'lead_score_updated_at' => now(),
                    'lifecycle_stage' => $stage,
                ]);

                // Record decay audit log
                LeadDecayLog::create([
                    'contact_id' => $contact->id,
                    'score_before' => $oldScore,
                    'score_after' => $newScore,
                    'score_decayed' => $pointsToDeduct,
                    'days_inactive' => $daysInactive,
                    'created_at' => now(),
                ]);

                // Record standard score audit log
                LeadScoreLog::create([
                    'contact_id' => $contact->id,
                    'event_type' => LeadScoringEventType::InactivityDecay->value,
                    'event_description' => "Inactivity decay: -{$pointsToDeduct} pts ({$daysInactive} days dormant)",
                    'score_change' => -$pointsToDeduct,
                    'score_after' => $newScore,
                    'created_at' => now(),
                ]);
            });

            $decayedCount++;
            $totalPointsDecayed += $pointsToDeduct;
        }

        return [
            'decayed_contacts_count' => $decayedCount,
            'total_points_decayed' => $totalPointsDecayed,
        ];
    }
}
