<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\LeadScoreLog;
use Odden\Marketing\Models\LeadScoringRule;
use Odden\Marketing\Support\ScoringRuleConditions;

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
        LeadScoringEventType::CustomEvent->value => 5,
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
        return DB::transaction(function () use ($contact, $eventType, $description, $context, $points): Contact {
            $rule = $this->matchingRule($contact, $eventType, $context ?? []);

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
            $mqlThreshold = (int) config('odden-marketing.sales_handoff.mql_score_threshold', 50);

            if ($newScore >= $sqlThreshold && $lifecycleStage !== LifecycleStage::Customer) {
                $lifecycleStage = LifecycleStage::SalesQualifiedLead;
            } elseif ($newScore >= $mqlThreshold && $lifecycleStage === LifecycleStage::Lead) {
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

    /**
     * The scoring rule for an event: among the active rules for the event type, the first whose
     * `conditions` match the contact, their company and the event context. Rules with conditions
     * are tried before unconditional ones, so a specific rule beats the catch-all.
     *
     * @param  array<string, mixed>  $context
     */
    protected function matchingRule(Contact $contact, LeadScoringEventType $eventType, array $context): ?LeadScoringRule
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, LeadScoringRule> $rules */
        $rules = LeadScoringRule::query()
            ->where('is_active', true)
            ->where('event_type', $eventType->value)
            ->orderBy('id')
            ->get();

        if ($rules->isEmpty()) {
            return null;
        }

        /** @var Company|null $company */
        $company = $rules->contains(fn (LeadScoringRule $rule): bool => ! empty($rule->conditions)) ? $contact->companies()->first() : null;

        $conditional = $rules->filter(fn (LeadScoringRule $rule): bool => ! empty($rule->conditions));
        $unconditional = $rules->reject(fn (LeadScoringRule $rule): bool => ! empty($rule->conditions));

        foreach ($conditional as $rule) {
            if (ScoringRuleConditions::matches($rule->conditions, $contact, $company, $context)) {
                return $rule;
            }
        }

        return $unconditional->first();
    }
}
