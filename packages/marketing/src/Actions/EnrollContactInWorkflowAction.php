<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\WorkflowEnrollmentStatus;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\MarketingForm;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Marketing\Models\WorkflowEnrollment;

class EnrollContactInWorkflowAction
{
    /**
     * Enroll a contact into a specific marketing workflow.
     */
    public function execute(MarketingWorkflow $workflow, Contact $contact): ?WorkflowEnrollment
    {
        if (! $workflow->is_active) {
            return null;
        }

        // Avoid concurrent active enrollment in the same workflow
        $existing = WorkflowEnrollment::query()
            ->where('workflow_id', $workflow->id)
            ->where('contact_id', $contact->id)
            ->where('status', WorkflowEnrollmentStatus::Active->value)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $firstStep = $workflow->steps()->orderBy('step_number', 'asc')->first();
        if ($firstStep === null) {
            return null;
        }

        /** @var WorkflowEnrollment $enrollment */
        $enrollment = WorkflowEnrollment::create([
            'workflow_id' => $workflow->id,
            'contact_id' => $contact->id,
            'current_step_id' => $firstStep->id,
            'status' => WorkflowEnrollmentStatus::Active,
            'next_run_at' => now(),
            'enrolled_at' => now(),
        ]);

        $workflow->increment('enrollments_count');

        // Immediately execute step 1 if no initial delay
        app(ExecuteWorkflowStepAction::class)->execute($enrollment);

        return $enrollment->fresh();
    }

    /**
     * Trigger workflows listening for form submission events.
     */
    public function triggerFormWorkflows(MarketingForm $form, Contact $contact): void
    {
        $workflows = MarketingWorkflow::query()
            ->where('is_active', true)
            ->where('trigger_type', WorkflowTriggerType::FormSubmitted->value)
            ->get();

        foreach ($workflows as $workflow) {
            $configuredFormId = $workflow->trigger_config['form_id'] ?? null;
            if ($configuredFormId === null || (int) $configuredFormId === $form->id) {
                $this->execute($workflow, $contact);
            }
        }
    }

    /**
     * Trigger workflows listening for custom in-app behavioral events.
     */
    public function triggerCustomEventWorkflows(string $eventName, Contact $contact): void
    {
        $workflows = MarketingWorkflow::query()
            ->where('is_active', true)
            ->where('trigger_type', WorkflowTriggerType::CustomEvent->value)
            ->get();

        foreach ($workflows as $workflow) {
            $configuredEvent = $workflow->trigger_config['event_name'] ?? null;
            if ($configuredEvent === null || strtolower((string) $configuredEvent) === strtolower($eventName)) {
                $this->execute($workflow, $contact);
            }
        }
    }

    /**
     * Trigger workflows listening for new contacts. An optional `lifecycle_stage` filter limits a
     * workflow to contacts created in that stage.
     */
    public function triggerContactCreatedWorkflows(Contact $contact): void
    {
        $workflows = MarketingWorkflow::query()
            ->where('is_active', true)
            ->where('trigger_type', WorkflowTriggerType::ContactCreated->value)
            ->get();

        foreach ($workflows as $workflow) {
            $stage = $workflow->trigger_config['lifecycle_stage'] ?? null;
            if ($stage === null || $contact->lifecycle_stage?->value === (string) $stage) {
                $this->execute($workflow, $contact);
            }
        }
    }

    /**
     * Trigger workflows listening for a contact joining an audience list. An optional `list_id`
     * filter limits a workflow to one list.
     */
    public function triggerListJoinedWorkflows(Contact $contact, int $listId): void
    {
        $workflows = MarketingWorkflow::query()
            ->where('is_active', true)
            ->where('trigger_type', WorkflowTriggerType::ListJoined->value)
            ->get();

        foreach ($workflows as $workflow) {
            $configuredListId = $workflow->trigger_config['list_id'] ?? null;
            if ($configuredListId === null || (int) $configuredListId === $listId) {
                $this->execute($workflow, $contact);
            }
        }
    }

    /**
     * Trigger workflows whose `score` threshold the contact just crossed (the score was below it
     * and is now at or above it). A workflow without a `score` never fires.
     */
    public function triggerLeadScoreWorkflows(Contact $contact, int $previousScore, int $newScore): void
    {
        if ($newScore <= $previousScore) {
            return;
        }

        $workflows = MarketingWorkflow::query()
            ->where('is_active', true)
            ->where('trigger_type', WorkflowTriggerType::LeadScoreReached->value)
            ->get();

        foreach ($workflows as $workflow) {
            $threshold = $workflow->trigger_config['score'] ?? null;
            if ($threshold !== null && $previousScore < (int) $threshold && $newScore >= (int) $threshold) {
                $this->execute($workflow, $contact);
            }
        }
    }
}
