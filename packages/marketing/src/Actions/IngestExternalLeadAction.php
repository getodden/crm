<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Enums\WorkflowEnrollmentStatus;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\MarketingWorkflow;
use Odden\Marketing\Models\WorkflowEnrollment;

class IngestExternalLeadAction
{
    public function __construct(
        public ApplyLeadScoringEventAction $scoringAction,
        public EnrollContactInWorkflowAction $enrollmentAction,
    ) {}

    /**
     * Ingest an external lead from Zapier, LinkedIn Lead Gen, or Zoom Webinar,
     * deduplicating by email, updating CRM attributes, awarding lead score points,
     * and auto-enrolling into matching nurture workflows.
     *
     * @param  array{
     *     email: string,
     *     first_name?: string|null,
     *     last_name?: string|null,
     *     company?: string|null,
     *     phone?: string|null,
     *     job_title?: string|null,
     *     source?: string|null,
     *     campaign?: string|null,
     *     properties?: array<string, mixed>|null
     * }  $payload
     * @return array{
     *     contact: Contact,
     *     is_new: bool,
     *     lead_score: int,
     *     enrolled_workflows_count: int
     * }
     */
    public function execute(array $payload): array
    {
        $email = mb_strtolower(trim($payload['email']));

        return DB::transaction(function () use ($email, $payload): array {
            /** @var Contact|null $contact */
            $contact = Contact::query()->where('email', $email)->first();
            $isNew = $contact === null;

            if ($contact === null) {
                $contact = new Contact([
                    'email' => $email,
                    'lifecycle_stage' => LifecycleStage::Lead,
                    'lead_status' => LeadStatus::New,
                    'lead_score' => 0,
                ]);
            }

            if (! empty($payload['first_name'])) {
                $contact->first_name = trim((string) $payload['first_name']);
            }

            if (! empty($payload['last_name'])) {
                $contact->last_name = trim((string) $payload['last_name']);
            }

            if (! empty($payload['phone'])) {
                $contact->phone = trim((string) $payload['phone']);
            }

            if (! empty($payload['job_title'])) {
                $contact->job_title = trim((string) $payload['job_title']);
            }

            // Merge custom properties & tracking source
            $props = $contact->properties ?? [];
            if (! empty($payload['source'])) {
                $props['lead_source'] = $payload['source'];
            }
            if (! empty($payload['campaign'])) {
                $props['lead_campaign'] = $payload['campaign'];
            }
            if (! empty($payload['properties'])) {
                $props = array_merge($props, $payload['properties']);
            }
            $contact->properties = $props;
            $contact->last_contacted_at = now();

            $contact->save();

            // Handle Company Association if specified
            if (! empty($payload['company'])) {
                $companyName = trim((string) $payload['company']);
                /** @var Company $company */
                $company = Company::firstOrCreate(
                    ['name' => $companyName],
                    ['domain' => str_contains($email, '@') ? explode('@', $email)[1] : null]
                );

                $contact->associateWith($company, 'primary');
            }

            // Award Lead Scoring (+15 Form Submission by default)
            $sourceLabel = $payload['source'] ?? 'External Webhook';
            $this->scoringAction->execute(
                contact: $contact,
                eventType: LeadScoringEventType::FormSubmission,
                description: "Ingested via {$sourceLabel}",
                context: ['source' => $sourceLabel, 'campaign' => $payload['campaign'] ?? null]
            );

            $contact->refresh();

            // Auto-enroll into matching active workflows
            $workflows = MarketingWorkflow::query()
                ->where('is_active', true)
                ->where('trigger_type', WorkflowTriggerType::FormSubmitted->value)
                ->get();

            $enrolledCount = 0;
            foreach ($workflows as $workflow) {
                // A workflow tied to one hosted form is for that form's submitters; an external lead didn't use it.
                if (($workflow->trigger_config['form_id'] ?? null) !== null) {
                    continue;
                }

                $alreadyActive = WorkflowEnrollment::query()
                    ->where('workflow_id', $workflow->id)
                    ->where('contact_id', $contact->id)
                    ->where('status', WorkflowEnrollmentStatus::Active->value)
                    ->exists();

                $enrollment = $this->enrollmentAction->execute($workflow, $contact);

                // Count only enrollments this lead actually started.
                if ($enrollment !== null && ! $alreadyActive) {
                    $enrolledCount++;
                }
            }

            return [
                'contact' => $contact,
                'is_new' => $isNew,
                'lead_score' => $contact->lead_score,
                'enrolled_workflows_count' => $enrolledCount,
            ];
        });
    }
}
