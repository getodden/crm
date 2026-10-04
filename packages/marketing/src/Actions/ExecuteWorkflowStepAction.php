<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Facades\Http;
use Odden\Core\Contracts\DealGateway;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Support\UserModel;
use Odden\Marketing\Enums\WorkflowEnrollmentStatus;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Mail\MarketingMessageMailable;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Models\WorkflowEnrollment;
use Odden\Marketing\Models\WorkflowLog;
use Odden\Marketing\Models\WorkflowStep;
use Odden\Marketing\Support\ContactPreferences;
use Odden\Marketing\Support\MarketingMailer;

class ExecuteWorkflowStepAction
{
    /** The most steps one call will run back to back before it stops the enrollment. */
    private const int MAX_CHAIN_STEPS = 100;

    /** @var array<int, true> Step ids already run in the current chain of back-to-back steps. */
    private array $chainVisited = [];

    private int $chainDepth = 0;

    /**
     * Execute the current step for an enrolled contact.
     *
     * The step is claimed first (WorkflowEnrollment::claimStep()), so when two workers run the
     * same due enrollment at once only one of them sends its email, SMS, or webhook; the other
     * returns without doing anything. Only a due enrollment (next_run_at set) can be claimed.
     */
    public function execute(WorkflowEnrollment $enrollment): void
    {
        // Steps that follow each other without a wait run in one call, so remember which ones have run in this
        // chain: reaching one a second time means the steps loop with no Delay in between.
        if ($this->chainDepth === 0) {
            $this->chainVisited = $enrollment->current_step_id !== null ? [(int) $enrollment->current_step_id => true] : [];
        }

        $this->chainDepth++;

        try {
            $this->runStep($enrollment);
        } finally {
            $this->chainDepth--;
        }
    }

    protected function runStep(WorkflowEnrollment $enrollment): void
    {
        if ($enrollment->status !== WorkflowEnrollmentStatus::Active) {
            return;
        }

        /** @var WorkflowStep|null $step */
        $step = $enrollment->currentStep;

        if ($step === null) {
            $this->completeEnrollment($enrollment);

            return;
        }

        if (! $enrollment->claimStep($step->id)) {
            return;
        }

        $contact = $enrollment->contact;
        $workflow = $enrollment->workflow;

        switch ($step->type) {
            case WorkflowStepType::SendEmail:
                $templateId = (int) ($step->config['template_id'] ?? 0);

                /** @var MarketingTemplate|null $template */
                $template = MarketingTemplate::query()->find($templateId);
                $body = $template->body_html ?? ($step->config['body'] ?? 'Hello from Odden Marketing!');

                $compiler = app(CompileCampaignMessageAction::class);
                $subject = $compiler->compileForContact(
                    (string) ($step->config['subject'] ?? $template->subject ?? 'Marketing Update'),
                    $contact,
                    escape: false,
                );

                $email = mb_strtolower(trim((string) $contact->email));
                // Unsubscribed, bounced or suppressed addresses are skipped, as is a contact not
                // subscribed to the step's optional subscription topic (config "topic_id").
                $topic = $step->config['topic_id'] ?? null;
                $suppressed = $email === '' || MarketingSubscription::isSuppressed($email, is_int($topic) || is_string($topic) ? $topic : null);

                if ($suppressed) {
                    WorkflowLog::create([
                        'enrollment_id' => $enrollment->id,
                        'step_id' => $step->id,
                        'contact_id' => $contact->id,
                        'action_taken' => "Skipped email (unsubscribed or suppressed): {$subject}",
                        'status' => 'skipped',
                        'details' => ['template_id' => $templateId, 'subject' => $subject],
                        'created_at' => now(),
                    ]);
                } else {
                    $rendered = $compiler->compileForContact((string) $body, $contact);

                    // Same delivery path as campaigns: a queued MarketingMessageMailable.
                    MarketingMailer::queue(new MarketingMessageMailable(
                        subjectLine: $subject,
                        htmlBody: $rendered,
                        textBody: $compiler->plainText($rendered),
                        fromEmail: (string) ($step->config['from_email'] ?? config('odden-marketing.defaults.sender_email')),
                        fromName: (string) ($step->config['from_name'] ?? config('odden-marketing.defaults.sender_name')),
                        replyToEmail: ($step->config['reply_to'] ?? config('odden-marketing.defaults.reply_to')) ?: null,
                        listUnsubscribeUrl: ContactPreferences::preferenceCenterUrl($contact),
                    ), $email);

                    // Record activity log on contact
                    $contact->logTask(
                        title: "Workflow Email: {$subject}",
                        dueAt: now(),
                        body: "Automated workflow email queued via workflow [{$workflow->name}]."
                    );

                    WorkflowLog::create([
                        'enrollment_id' => $enrollment->id,
                        'step_id' => $step->id,
                        'contact_id' => $contact->id,
                        'action_taken' => "Sent email: {$subject}",
                        'status' => 'success',
                        'details' => ['template_id' => $templateId, 'subject' => $subject],
                        'created_at' => now(),
                    ]);
                }

                $nextStepNum = array_key_exists('next_step', $step->config)
                    ? ($step->config['next_step'] !== null ? (int) $step->config['next_step'] : null)
                    : ($step->step_number + 1);

                $this->advanceToNextStep($enrollment, $nextStepNum);
                break;

            case WorkflowStepType::Delay:
                $delayMinutes = max(1, (int) ($step->config['delay_minutes'] ?? 60));

                WorkflowLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'contact_id' => $contact->id,
                    'action_taken' => "Wait timer started ({$delayMinutes} minutes)",
                    'status' => 'success',
                    'details' => ['delay_minutes' => $delayMinutes],
                    'created_at' => now(),
                ]);

                // Point to next step but schedule execution in the future
                /** @var WorkflowStep|null $nextStep */
                $nextStep = $workflow->steps()->where('step_number', $step->step_number + 1)->first();
                if ($nextStep !== null) {
                    $enrollment->update([
                        'current_step_id' => $nextStep->id,
                        'next_run_at' => now()->addMinutes($delayMinutes),
                    ]);
                } else {
                    $this->completeEnrollment($enrollment);
                }
                break;

            case WorkflowStepType::Condition:
                $property = (string) ($step->config['property'] ?? 'lead_score');
                $operator = (string) ($step->config['operator'] ?? '>=');
                $targetVal = $step->config['value'] ?? 50;

                $actualVal = match ($property) {
                    'lead_score' => $contact->lead_score,
                    'lifecycle_stage' => $contact->lifecycle_stage->value,
                    default => $contact->properties[$property] ?? null,
                };

                $conditionMet = match ($operator) {
                    '>=' => (int) $actualVal >= (int) $targetVal,
                    '>' => (int) $actualVal > (int) $targetVal,
                    '<=' => (int) $actualVal <= (int) $targetVal,
                    '<' => (int) $actualVal < (int) $targetVal,
                    '!=' => (string) $actualVal !== (string) $targetVal,
                    default => (string) $actualVal === (string) $targetVal,
                };

                $nextStepNum = $conditionMet
                    ? ($step->next_step_on_true ?? ($step->step_number + 1))
                    : ($step->next_step_on_false ?? null);

                WorkflowLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'contact_id' => $contact->id,
                    'action_taken' => 'Evaluated condition: '.($conditionMet ? 'Passed (True)' : 'Failed (False)'),
                    'status' => 'success',
                    'details' => ['property' => $property, 'result' => $conditionMet, 'next_step' => $nextStepNum],
                    'created_at' => now(),
                ]);

                if ($nextStepNum !== null) {
                    $this->advanceToNextStep($enrollment, $nextStepNum);
                } else {
                    $this->completeEnrollment($enrollment);
                }
                break;

            case WorkflowStepType::UpdateContact:
                $updates = [];
                if (isset($step->config['lifecycle_stage'])) {
                    $updates['lifecycle_stage'] = LifecycleStage::from((string) $step->config['lifecycle_stage']);
                }
                if (! empty($updates)) {
                    $contact->update($updates);
                }

                WorkflowLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'contact_id' => $contact->id,
                    'action_taken' => 'Updated contact properties',
                    'status' => 'success',
                    'details' => $updates,
                    'created_at' => now(),
                ]);

                $nextStepNum = array_key_exists('next_step', $step->config)
                    ? ($step->config['next_step'] !== null ? (int) $step->config['next_step'] : null)
                    : ($step->step_number + 1);

                $this->advanceToNextStep($enrollment, $nextStepNum);
                break;

            case WorkflowStepType::SendSms:
                $message = (string) ($step->config['message'] ?? 'Marketing Update');
                $requiresConsent = (bool) ($step->config['requires_consent'] ?? true);

                /** @var DispatchSmsAction $dispatchSms */
                $dispatchSms = app(DispatchSmsAction::class);
                $sms = $dispatchSms->execute($contact, $message, null, $requiresConsent, "Workflow SMS: {$workflow->name}");

                $status = $sms->status === 'delivered' ? 'success' : 'skipped';
                $actionTaken = $sms->status === 'delivered'
                    ? "Dispatched SMS to {$contact->phone}"
                    : "Skipped SMS ({$sms->error_message})";

                WorkflowLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'contact_id' => $contact->id,
                    'action_taken' => $actionTaken,
                    'status' => $status,
                    'details' => [
                        'phone' => $contact->phone,
                        'message' => $sms->message_body,
                        'sms_id' => $sms->id,
                        'status' => $sms->status,
                        'error' => $sms->error_message,
                    ],
                    'created_at' => now(),
                ]);

                $nextStepNum = array_key_exists('next_step', $step->config)
                    ? ($step->config['next_step'] !== null ? (int) $step->config['next_step'] : null)
                    : ($step->step_number + 1);

                $this->advanceToNextStep($enrollment, $nextStepNum);
                break;

            case WorkflowStepType::AssignOwner:
                $ownerId = $step->config['owner_id'] ?? null;
                if ($ownerId === null) {
                    $ownerId = UserModel::query()->first()?->getKey();
                }

                if ($ownerId !== null) {
                    $contact->update(['owner_id' => (int) $ownerId]);
                }

                WorkflowLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'contact_id' => $contact->id,
                    'action_taken' => "Assigned sales owner #{$ownerId} to contact",
                    'status' => 'success',
                    'details' => ['owner_id' => $ownerId],
                    'created_at' => now(),
                ]);

                $nextStepNum = array_key_exists('next_step', $step->config)
                    ? ($step->config['next_step'] !== null ? (int) $step->config['next_step'] : null)
                    : ($step->step_number + 1);

                $this->advanceToNextStep($enrollment, $nextStepNum);
                break;

            case WorkflowStepType::CreateDeal:
                $dealName = (string) ($step->config['deal_name'] ?? "Deal for {$contact->first_name} {$contact->last_name}");
                $amount = (float) ($step->config['amount'] ?? 10000.00);
                $pipelineId = $step->config['pipeline_id'] ?? null;
                $stageId = $step->config['stage_id'] ?? null;

                $deal = app()->bound(DealGateway::class)
                    ? app(DealGateway::class)->createOpenDeal(
                        contact: $contact,
                        name: $dealName,
                        amount: $amount,
                        pipelineId: $pipelineId !== null ? (int) $pipelineId : null,
                        stageId: $stageId !== null ? (int) $stageId : null,
                    )
                    : null;

                WorkflowLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'contact_id' => $contact->id,
                    'action_taken' => $deal !== null ? "Created Deal: {$dealName} (\${$amount})" : 'Skipped Deal creation (no pipeline)',
                    'status' => $deal !== null ? 'success' : 'skipped',
                    'details' => ['deal_id' => $deal?->id, 'amount' => $amount, 'name' => $dealName],
                    'created_at' => now(),
                ]);

                $nextStepNum = array_key_exists('next_step', $step->config)
                    ? ($step->config['next_step'] !== null ? (int) $step->config['next_step'] : null)
                    : ($step->step_number + 1);

                $this->advanceToNextStep($enrollment, $nextStepNum);
                break;

            case WorkflowStepType::CreateSalesTask:
                $taskTitle = (string) ($step->config['title'] ?? 'High-Priority Lead Follow-up');
                $dueInHours = (int) ($step->config['due_in_hours'] ?? 2);

                $contact->logTask(
                    title: $taskTitle,
                    dueAt: now()->addHours($dueInHours),
                    body: "Priority sales SLA follow-up task automatically created via workflow [{$workflow->name}]."
                );

                WorkflowLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'contact_id' => $contact->id,
                    'action_taken' => "Created sales task: {$taskTitle}",
                    'status' => 'success',
                    'details' => ['title' => $taskTitle, 'due_in_hours' => $dueInHours],
                    'created_at' => now(),
                ]);

                $nextStepNum = array_key_exists('next_step', $step->config)
                    ? ($step->config['next_step'] !== null ? (int) $step->config['next_step'] : null)
                    : ($step->step_number + 1);

                $this->advanceToNextStep($enrollment, $nextStepNum);
                break;

            case WorkflowStepType::InternalNotification:
                $alertMessage = (string) ($step->config['message'] ?? 'High-intent lead requires sales attention');

                $contact->logTask(
                    title: "Internal Alert: {$alertMessage}",
                    dueAt: now(),
                    body: "Automated alert generated by workflow [{$workflow->name}]."
                );

                WorkflowLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'contact_id' => $contact->id,
                    'action_taken' => "Logged internal notification: {$alertMessage}",
                    'status' => 'success',
                    'details' => ['message' => $alertMessage],
                    'created_at' => now(),
                ]);

                $nextStepNum = array_key_exists('next_step', $step->config)
                    ? ($step->config['next_step'] !== null ? (int) $step->config['next_step'] : null)
                    : ($step->step_number + 1);

                $this->advanceToNextStep($enrollment, $nextStepNum);
                break;

            case WorkflowStepType::Webhook:
                $webhookUrl = (string) ($step->config['url'] ?? '');
                $method = strtoupper((string) ($step->config['method'] ?? 'POST'));

                $status = 'success';
                $responseCode = 200;

                if (! empty($webhookUrl)) {
                    try {
                        $payload = [
                            'workflow_id' => $workflow->id,
                            'workflow_name' => $workflow->name,
                            'contact' => [
                                'id' => $contact->id,
                                'email' => $contact->email,
                                'first_name' => $contact->first_name,
                                'last_name' => $contact->last_name,
                                'lead_score' => $contact->lead_score,
                                'lifecycle_stage' => $contact->lifecycle_stage->value,
                            ],
                            'timestamp' => now()->toIso8601String(),
                        ];

                        // Signed with the step's own secret, or the webhook secret that is configured for outbound
                        // marketing webhooks. Never with the application key: it is not the receiver's to know, and on a
                        // shared install it would be one key for every workspace. Without a secret the call is unsigned.
                        $stepSecret = $step->config['secret'] ?? null;
                        $secret = is_string($stepSecret) && $stepSecret !== '' ? $stepSecret : config('odden-marketing.webhooks.secret');
                        $jsonPayload = (string) json_encode($payload);
                        $signature = is_string($secret) && $secret !== '' ? hash_hmac('sha256', $jsonPayload, $secret) : null;

                        $customHeaders = isset($step->config['headers']) && is_array($step->config['headers'])
                            ? $step->config['headers']
                            : [];

                        $headers = array_merge(array_filter([
                            'X-Odden-Signature' => $signature,
                            'X-Odden-Workflow-ID' => (string) $workflow->id,
                            'User-Agent' => 'Odden-RevOps-Webhook/1.0',
                        ], fn (?string $value): bool => $value !== null), $customHeaders);

                        $response = Http::withHeaders($headers)
                            ->timeout(5)
                            ->send($method, $webhookUrl, [
                                'json' => $payload,
                            ]);
                        $responseCode = $response->status();
                        $status = $response->successful() ? 'success' : 'failed';
                    } catch (\Throwable) {
                        $status = 'failed';
                        $responseCode = 500;
                    }
                }

                WorkflowLog::create([
                    'enrollment_id' => $enrollment->id,
                    'step_id' => $step->id,
                    'contact_id' => $contact->id,
                    'action_taken' => "Webhook {$method} to {$webhookUrl}",
                    'status' => $status,
                    'details' => ['url' => $webhookUrl, 'method' => $method, 'response_code' => $responseCode],
                    'created_at' => now(),
                ]);

                $nextStepNum = array_key_exists('next_step', $step->config)
                    ? ($step->config['next_step'] !== null ? (int) $step->config['next_step'] : null)
                    : ($step->step_number + 1);

                $this->advanceToNextStep($enrollment, $nextStepNum);
                break;
        }
    }

    /**
     * Advance enrollment to specified step number and recursively run next step if ready.
     */
    protected function advanceToNextStep(WorkflowEnrollment $enrollment, ?int $stepNumber): void
    {
        if ($stepNumber === null) {
            $this->completeEnrollment($enrollment);

            return;
        }

        /** @var WorkflowStep|null $nextStep */
        $nextStep = $enrollment->workflow->steps()->where('step_number', $stepNumber)->first();

        if ($nextStep === null) {
            $this->completeEnrollment($enrollment);

            return;
        }

        $enrollment->update([
            'current_step_id' => $nextStep->id,
            'next_run_at' => now(),
        ]);

        // A Delay step ends the chain (it schedules the next run), so only other steps can loop.
        if ($nextStep->type !== WorkflowStepType::Delay) {
            if (isset($this->chainVisited[$nextStep->id]) || count($this->chainVisited) >= self::MAX_CHAIN_STEPS) {
                $this->exitLoopingEnrollment($enrollment, $nextStep);

                return;
            }

            $this->chainVisited[$nextStep->id] = true;
        }

        $this->execute($enrollment->fresh() ?? $enrollment);
    }

    /**
     * Stop an enrollment whose steps loop back on themselves without a Delay, and say why in its log.
     */
    protected function exitLoopingEnrollment(WorkflowEnrollment $enrollment, WorkflowStep $step): void
    {
        $exited = WorkflowEnrollment::query()
            ->whereKey($enrollment->getKey())
            ->where('status', WorkflowEnrollmentStatus::Active->value)
            ->update(['status' => WorkflowEnrollmentStatus::Exited->value, 'next_run_at' => null]) === 1;

        if (! $exited) {
            return;
        }

        WorkflowLog::create([
            'enrollment_id' => $enrollment->id,
            'step_id' => $step->id,
            'contact_id' => $enrollment->contact_id,
            'action_taken' => "Stopped: step {$step->step_number} would run again with no wait in between. Add a Delay step to the loop.",
            'status' => 'failed',
            'details' => ['step_number' => $step->step_number],
            'created_at' => now(),
        ]);
    }

    /**
     * Mark an enrollment as successfully completed.
     */
    protected function completeEnrollment(WorkflowEnrollment $enrollment): void
    {
        if ($enrollment->claimCompletion()) {
            $enrollment->workflow->increment('completed_count');
        }
    }
}
