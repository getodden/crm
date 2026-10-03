<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\LeadScoreLog;
use Odden\Marketing\Models\LeadScoringRule;

class ApplyLeadScoringEventAction
{
    /**
     * Default point values per event type when no specific rule overrides it.
     */
    protected const DEFAULT_SCORES = [
        LeadScoringEventType::FormSubmission->value => 15,
        LeadScoringEventType::EmailOpened->value => 3,
        LeadScoringEventType::EmailClicked->value => 10,
        LeadScoringEventType::Unsubscribed->value => -50,
        LeadScoringEventType::InactivityDecay->value => -10,
        LeadScoringEventType::PropertyMatch->value => 20,
    ];

    /**
     * Apply a scoring event to a contact, recalculate total score, log audit entry,
     * and auto-qualify lifecycle stage (Lead -> MQL -> SQL).
     *
     * @param  array<string, mixed>|null  $context
     */
    public function execute(
        Contact $contact,
        LeadScoringEventType $eventType,
        ?string $description = null,
        ?array $context = null,
        ?int $points = null
    ): Contact {
        return DB::transaction(function () use ($contact, $eventType, $description, $points): Contact {
            // Check for custom active rule
            /** @var LeadScoringRule|null $rule */
            $rule = LeadScoringRule::query()
                ->where('is_active', true)
                ->where('event_type', $eventType->value)
                ->first();

            $scoreDelta = $points ?? ($rule !== null
                ? $rule->score_change
                : (self::DEFAULT_SCORES[$eventType->value] ?? 5));

            $newScore = max(0, $contact->lead_score + $scoreDelta);
            $eventDesc = $description ?? $eventType->label();

            // Create score log
            LeadScoreLog::create([
                'contact_id' => $contact->id,
                'rule_id' => $rule?->id,
                'event_type' => $eventType->value,
                'event_description' => $eventDesc,
                'score_change' => $scoreDelta,
                'score_after' => $newScore,
                'created_at' => now(),
            ]);

            // Lifecycle Stage Qualification
            $previousStage = $contact->lifecycle_stage ?? LifecycleStage::Lead;
            $lifecycleStage = $previousStage;
            $sqlThreshold = (int) config('odden-marketing.sales_handoff.sql_score_threshold', 100);

            if ($newScore >= $sqlThreshold && $lifecycleStage !== LifecycleStage::Customer) {
                $lifecycleStage = LifecycleStage::SalesQualifiedLead;
            } elseif ($newScore >= 50 && $lifecycleStage === LifecycleStage::Lead) {
                $lifecycleStage = LifecycleStage::MarketingQualifiedLead;
            }

            $previousScore = (int) $contact->lead_score;

            $contact->update([
                'lead_score' => $newScore,
                'lead_score_updated_at' => now(),
                'lifecycle_stage' => $lifecycleStage,
            ]);

            // Workflows that wait for a score threshold
            app(EnrollContactInWorkflowAction::class)->triggerLeadScoreWorkflows($contact, $previousScore, $newScore);

            // Trigger instant sales hand-off on promotion to SQL
            if (
                $lifecycleStage === LifecycleStage::SalesQualifiedLead
                && $previousStage !== LifecycleStage::SalesQualifiedLead
                && (bool) config('odden-marketing.sales_handoff.auto_handoff_on_sql', true)
            ) {
                app(HandoffLeadToSalesAction::class)->execute($contact);
            }

            return $contact->fresh() ?? $contact;
        });
    }
}
