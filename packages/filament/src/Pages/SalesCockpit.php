<?php

declare(strict_types=1);

namespace Odden\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Filament\Pages\Concerns\AuthorizesPageAccess;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Resources\QuoteResource;
use Odden\Filament\Resources\SalesSequenceResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Enums\CallDisposition;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;
use UnitEnum;

class SalesCockpit extends Page
{
    use AuthorizesPageAccess;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::RocketLaunch;

    protected static ?string $navigationLabel = 'Sales Cockpit';

    protected static ?string $title = 'Sales Prospecting Workspace';

    protected string $view = 'odden-filament::pages.sales-cockpit';

    public string $currentWorkspaceTab = 'summary';

    public ?int $selectedUserId = null;

    public string $taskTimeframe = 'today';

    public string $scheduleTab = 'schedule';

    public int $scheduleDayOffset = 0;

    public int $sequenceIndex = 0;

    public string $activeTab = 'all';

    public bool $showCallModal = false;

    public ?int $callContactId = null;

    public string $callDisposition = 'connected';

    public int $callDurationMinutes = 5;

    public string $callNotes = '';

    public bool $createFollowUpTask = false;

    public ?string $followUpTaskDate = null;

    public string $followUpTaskTitle = 'Follow-up Call';

    public bool $showMeetingModal = false;

    public ?int $meetingContactId = null;

    public string $meetingTitle = 'Discovery & Demo Call';

    public ?string $meetingDate = null;

    public string $meetingTime = '10:00';

    public int $meetingDurationMinutes = 30;

    public string $meetingNotes = '';

    /**
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    protected static function getAuthorizationResources(): array
    {
        return [
            ContactResource::class,
            DealResource::class,
            QuoteResource::class,
            SalesSequenceResource::class,
        ];
    }

    public function mount(): void
    {
        $this->selectedUserId = (int) OddenAuthorization::userId();
    }

    public function setWorkspaceTab(string $tab): void
    {
        $this->currentWorkspaceTab = $tab;
    }

    public function setTaskTimeframe(string $timeframe): void
    {
        if (in_array($timeframe, ['today', 'week', 'overdue'], true)) {
            $this->taskTimeframe = $timeframe;
        }
    }

    public function setScheduleTab(string $tab): void
    {
        if (in_array($tab, ['schedule', 'insights', 'feed'], true)) {
            $this->scheduleTab = $tab;
        }
    }

    public function nextScheduleDay(): void
    {
        $this->scheduleDayOffset++;
    }

    public function previousScheduleDay(): void
    {
        $this->scheduleDayOffset--;
    }

    public function resetScheduleToday(): void
    {
        $this->scheduleDayOffset = 0;
    }

    public function nextSequence(): void
    {
        $count = $this->getSequencesListProperty()->count();
        if ($count > 0) {
            $this->sequenceIndex = ($this->sequenceIndex + 1) % $count;
        }
    }

    public function previousSequence(): void
    {
        $count = $this->getSequencesListProperty()->count();
        if ($count > 0) {
            $this->sequenceIndex = ($this->sequenceIndex - 1 + $count) % $count;
        }
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['all', 'closing', 'prospecting'], true)) {
            $this->activeTab = $tab;
        }
    }

    /**
     * @return Collection<int, Model>
     */
    public function getUsersProperty(): Collection
    {
        return UserModel::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, SalesSequence>
     */
    public function getSequencesListProperty(): Collection
    {
        return SalesSequence::query()
            ->where('is_active', true)
            ->with(['enrollments' => fn ($q) => $q->where('status', 'active')->with('contact')])
            ->orderBy('id')
            ->get();
    }

    public function getCurrentSequenceProperty(): ?SalesSequence
    {
        $sequences = $this->getSequencesListProperty();
        if ($sequences->isEmpty()) {
            return null;
        }

        return $sequences->values()->get($this->sequenceIndex) ?? $sequences->first();
    }

    /**
     * @return array{
     *     total_due_today: int,
     *     high_priority: int,
     *     all_tasks: int,
     *     todos: int,
     *     calls: int,
     *     emails: int,
     *     linkedin: int
     * }
     */
    public function getTaskStatsProperty(): array
    {
        $todayStart = Carbon::today()->startOfDay();
        $todayEnd = Carbon::today()->endOfDay();
        $weekEnd = Carbon::today()->endOfWeek();

        $baseQuery = Activity::query()
            ->where('status', ActivityStatus::Pending);

        if ($this->selectedUserId) {
            $baseQuery->where(function ($q): void {
                $q->whereNull('creator_id')->orWhere('creator_id', $this->selectedUserId);
            });
        }

        $allTasksCount = (clone $baseQuery)->count();

        $dueQuery = match ($this->taskTimeframe) {
            'week' => (clone $baseQuery)->where(function ($q) use ($weekEnd): void {
                $q->whereNull('due_at')->orWhere('due_at', '<=', $weekEnd);
            }),
            'overdue' => (clone $baseQuery)->whereNotNull('due_at')->where('due_at', '<', $todayStart),
            default => (clone $baseQuery)->where(function ($q) use ($todayEnd): void {
                $q->whereNull('due_at')->orWhere('due_at', '<=', $todayEnd);
            }),
        };

        $dueCount = (clone $dueQuery)->count();

        $overdue = (clone $baseQuery)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $todayStart)
            ->count();

        $todos = (clone $dueQuery)->where('type', ActivityType::Task)->count();
        $calls = (clone $dueQuery)->where('type', ActivityType::Call)->count();
        $emails = (clone $dueQuery)->where('type', ActivityType::Email)->count();
        $linkedin = (clone $dueQuery)->where('type', ActivityType::LinkedIn)->count();

        return [
            'total_due_today' => $dueCount,
            'high_priority' => $overdue,
            'all_tasks' => $allTasksCount,
            'todos' => $todos,
            'calls' => $calls,
            'emails' => $emails,
            'linkedin' => $linkedin,
        ];
    }

    /**
     * @return array{
     *     active_sequences: int,
     *     total_enrolled: int,
     *     steps_due: int,
     *     current_sequence_name: string,
     *     current_enrolled_count: int,
     *     current_step_number: int,
     *     current_step_title: string,
     *     current_due_today: int,
     *     current_overdue: int,
     *     current_remaining_steps: int,
     *     current_index_display: string
     * }
     */
    public function getSequenceCardStatsProperty(): array
    {
        $todayStr = Carbon::today()->toDateString();
        $sequences = $this->getSequencesListProperty();
        $totalSequences = $sequences->count();

        $allEnrollments = SalesSequenceEnrollment::query()->where('status', 'active');
        $totalEnrolled = (clone $allEnrollments)->count();
        $stepsDue = (clone $allEnrollments)->where(function ($q) use ($todayStr): void {
            $q->whereNull('next_step_due_at')->orWhere('next_step_due_at', '<=', $todayStr);
        })->count();

        $currentSeq = $this->getCurrentSequenceProperty();

        if (! $currentSeq) {
            return [
                'active_sequences' => $totalSequences,
                'total_enrolled' => $totalEnrolled,
                'steps_due' => $stepsDue,
                'current_sequence_name' => 'No active sequences',
                'current_enrolled_count' => 0,
                'current_step_number' => 1,
                'current_step_title' => 'Create your first sequence to start prospecting',
                'current_due_today' => 0,
                'current_overdue' => 0,
                'current_remaining_steps' => 0,
                'current_index_display' => '0/0',
            ];
        }

        $enrollments = $currentSeq->enrollments;
        $enrolledCount = $enrollments->count();

        $dueToday = $enrollments->filter(function (SalesSequenceEnrollment $e) use ($todayStr): bool {
            return $e->next_step_due_at === null || $e->next_step_due_at->toDateString() === $todayStr;
        })->count();

        $overdue = $enrollments->filter(function (SalesSequenceEnrollment $e) use ($todayStr): bool {
            return $e->next_step_due_at !== null && $e->next_step_due_at->toDateString() < $todayStr;
        })->count();

        $steps = $currentSeq->steps ?? [];
        $totalSteps = count($steps);

        /** @var SalesSequenceEnrollment|null $firstEnrollment */
        $firstEnrollment = $enrollments->first();
        $stepIndex = $firstEnrollment !== null ? $firstEnrollment->current_step - 1 : 0;
        $stepDef = $steps[$stepIndex] ?? null;
        $stepTitle = $stepDef !== null ? $stepDef['title'] : 'Sequence Outreach';
        $remainingSteps = max(0, $totalSteps - ($stepIndex + 1));

        $currDisplayIndex = ($this->sequenceIndex + 1).'/'.max(1, $totalSequences);

        return [
            'active_sequences' => $totalSequences,
            'total_enrolled' => $totalEnrolled,
            'steps_due' => $stepsDue,
            'current_sequence_name' => $currentSeq->name,
            'current_enrolled_count' => $enrolledCount,
            'current_step_number' => $stepIndex + 1,
            'current_step_title' => $stepTitle,
            'current_due_today' => $dueToday,
            'current_overdue' => $overdue,
            'current_remaining_steps' => $remainingSteps,
            'current_index_display' => $currDisplayIndex,
        ];
    }

    /**
     * @return Collection<int, Activity>
     */
    public function getScheduleActivitiesProperty(): Collection
    {
        $selectedDate = Carbon::today()->addDays($this->scheduleDayOffset);
        $start = (clone $selectedDate)->startOfDay();
        $end = (clone $selectedDate)->endOfDay();

        return Activity::query()
            ->whereBetween('due_at', [$start, $end])
            ->whereIn('type', [ActivityType::Meeting, ActivityType::Call])
            ->with(['subject'])
            ->orderBy('due_at')
            ->get();
    }

    public function getSelectedDateFormattedProperty(): string
    {
        $date = Carbon::today()->addDays($this->scheduleDayOffset);
        if ($this->scheduleDayOffset === 0) {
            return 'Today, '.$date->format('M j');
        }

        return $date->format('l, M j');
    }

    /**
     * @return list<array{
     *     id: string,
     *     category: 'prospecting'|'closing',
     *     title: string,
     *     subtitle: string,
     *     badge: string,
     *     badge_color: string,
     *     type: string,
     *     contact_id: int|null,
     *     deal_id: int|null,
     *     quote_id: int|null,
     *     enrollment_id: int|null,
     *     lead_status: string,
     *     timezone: string,
     *     last_touch: string,
     *     due: string,
     *     action_label: string,
     *     action_type: string,
     *     step_title: string|null,
     *     avatar_initials: string,
     *     avatars: list<array{initials: string, name: string}>,
     *     url: string|null
     * }>
     */
    public function getGuidedActionsProperty(): array
    {
        $actions = [];

        // 1. Prospecting: Sequence steps due
        $dueEnrollments = SalesSequenceEnrollment::query()
            ->where('status', 'active')
            ->where(function ($q): void {
                $q->whereNull('next_step_due_at')
                    ->orWhere('next_step_due_at', '<=', Carbon::today()->toDateString());
            })
            ->with(['contact', 'sequence'])
            ->limit(6)
            ->get();

        foreach ($dueEnrollments as $enrollment) {
            $contact = $enrollment->contact;
            $sequence = $enrollment->sequence;

            $stepIndex = $enrollment->current_step - 1;
            $stepDef = $sequence->steps[$stepIndex] ?? null;
            $stepTitle = $stepDef !== null ? $stepDef['title'] : 'Sequence Outreach';
            $stepType = $stepDef !== null ? $stepDef['type'] : 'task';

            $actions[] = [
                'id' => "enrollment_{$enrollment->id}",
                'category' => 'prospecting',
                'title' => $contact->full_name,
                'subtitle' => "{$sequence->name} • Step {$enrollment->current_step} of {$sequence->totalSteps()}",
                'badge' => 'Sequence Step Due',
                'badge_color' => 'amber',
                'type' => $stepType,
                'contact_id' => $contact->id,
                'deal_id' => null,
                'quote_id' => null,
                'enrollment_id' => $enrollment->id,
                'lead_status' => $contact->lead_status->label(),
                'timezone' => $contact->timezone ?? 'UTC',
                'last_touch' => $contact->last_contacted_at !== null ? $contact->last_contacted_at->diffForHumans() : 'Never',
                'due' => $enrollment->next_step_due_at !== null ? 'Due '.$enrollment->next_step_due_at->format('M d') : 'Due today',
                'action_label' => 'Complete Step',
                'action_type' => 'advance_sequence',
                'step_title' => $stepTitle,
                'avatar_initials' => $this->getInitials($contact->full_name),
                'avatars' => [
                    ['initials' => $this->getInitials($contact->full_name), 'name' => $contact->full_name],
                ],
                'url' => ContactResource::getUrl('edit', ['record' => $contact->id]),
            ];
        }

        // 2. Prospecting: New uncontacted leads
        $newContacts = Contact::query()
            ->where(function ($q): void {
                $q->where('lead_status', LeadStatus::New)
                    ->orWhereNull('last_contacted_at');
            })
            ->with(['companies'])
            ->latest('created_at')
            ->limit(6)
            ->get();

        foreach ($newContacts as $contact) {
            $companyName = $contact->companies->first()?->name;
            $subtitle = trim(($contact->job_title ? "{$contact->job_title}" : '').($companyName ? " • {$companyName}" : ''));
            if ($subtitle === '') {
                $subtitle = $contact->email;
            }

            $actions[] = [
                'id' => "contact_{$contact->id}",
                'category' => 'prospecting',
                'title' => $contact->full_name,
                'subtitle' => $subtitle,
                'badge' => 'New Lead',
                'badge_color' => 'blue',
                'type' => 'prospecting',
                'contact_id' => $contact->id,
                'deal_id' => null,
                'quote_id' => null,
                'enrollment_id' => null,
                'lead_status' => $contact->lead_status->label(),
                'timezone' => $contact->timezone ?? 'UTC',
                'last_touch' => $contact->last_contacted_at !== null ? $contact->last_contacted_at->diffForHumans() : 'Never',
                'due' => 'Needs initial touch',
                'action_label' => 'Log Touch',
                'action_type' => 'touch',
                'step_title' => 'Initial outreach needed',
                'avatar_initials' => $this->getInitials($contact->full_name),
                'avatars' => [
                    ['initials' => $this->getInitials($contact->full_name), 'name' => $contact->full_name],
                ],
                'url' => ContactResource::getUrl('edit', ['record' => $contact->id]),
            ];
        }

        // 3. Closing: Rotten / Stale Deals
        $openDeals = Deal::query()
            ->where('status', DealStatus::Open)
            ->with(['stage', 'contacts'])
            ->get();

        foreach ($openDeals as $deal) {
            if ($deal->isRotten()) {
                $days = $deal->daysInCurrentStage();
                $rotLimit = $deal->stage->rot_after_days ?? 14;

                $dealAvatars = [];
                foreach ($deal->contacts->take(3) as $c) {
                    $dealAvatars[] = ['initials' => $this->getInitials($c->full_name), 'name' => $c->full_name];
                }
                if (empty($dealAvatars)) {
                    $dealAvatars[] = ['initials' => $this->getInitials($deal->name), 'name' => $deal->name];
                }

                $actions[] = [
                    'id' => "deal_{$deal->id}",
                    'category' => 'closing',
                    'title' => $deal->name,
                    'subtitle' => '$'.number_format((float) $deal->amount, 2)." • Stage: {$deal->stage->name} ({$days}d inactive)",
                    'badge' => "Stale ({$days}d > {$rotLimit}d)",
                    'badge_color' => 'rose',
                    'type' => 'deal',
                    'contact_id' => $deal->contacts->first()?->id,
                    'deal_id' => $deal->id,
                    'quote_id' => null,
                    'enrollment_id' => null,
                    'lead_status' => 'Deal Rotting',
                    'timezone' => 'N/A',
                    'last_touch' => "{$days} days ago",
                    'due' => 'Revival touch overdue',
                    'action_label' => 'View Deal',
                    'action_type' => 'view_deal',
                    'step_title' => 'Re-engage buyer on deal stalled in '.$deal->stage->name,
                    'avatar_initials' => $this->getInitials($deal->name),
                    'avatars' => $dealAvatars,
                    'url' => DealResource::getUrl('view', ['record' => $deal->id]),
                ];
            }
        }

        // 4. Closing: Pending quotes awaiting response
        $quotes = Quote::query()
            ->where('status', QuoteStatus::Sent)
            ->with(['deal.contacts'])
            ->latest()
            ->limit(5)
            ->get();

        foreach ($quotes as $quote) {
            $deal = $quote->deal;
            $dealName = $deal->name;
            $contact = $deal->contacts->first();
            $contactName = $contact !== null ? $contact->full_name : 'Client';

            $actions[] = [
                'id' => "quote_{$quote->id}",
                'category' => 'closing',
                'title' => "Quote #{$quote->quote_number} • {$dealName}",
                'subtitle' => '$'.number_format((float) $quote->total_amount, 2)." sent to {$contactName}",
                'badge' => 'Awaiting Acceptance',
                'badge_color' => 'purple',
                'type' => 'quote',
                'contact_id' => $contact?->id,
                'deal_id' => $quote->deal_id,
                'quote_id' => $quote->id,
                'enrollment_id' => null,
                'lead_status' => 'Quote Sent',
                'timezone' => $contact !== null ? ($contact->timezone ?? 'UTC') : 'UTC',
                'last_touch' => $quote->created_at !== null ? $quote->created_at->diffForHumans() : 'Recently',
                'due' => 'Closing follow-up',
                'action_label' => 'View Quote',
                'action_type' => 'view_quote',
                'step_title' => 'Follow up on sent quote proposal',
                'avatar_initials' => 'QT',
                'avatars' => [
                    ['initials' => 'QT', 'name' => "Quote #{$quote->quote_number}"],
                ],
                'url' => QuoteResource::getUrl('edit', ['record' => $quote->id]),
            ];
        }

        return $actions;
    }

    /**
     * Start the first actionable item in the queue.
     */
    public function startAllGuidedActions(): void
    {
        $actions = $this->getGuidedActionsProperty();
        if (empty($actions)) {
            Notification::make()
                ->title('All Caught Up!')
                ->body('No pending guided actions in your queue.')
                ->success()
                ->send();

            return;
        }

        $first = $actions[0];
        if ($first['action_type'] === 'advance_sequence' && $first['enrollment_id']) {
            $this->advanceEnrollment($first['enrollment_id']);
        } elseif ($first['action_type'] === 'touch' && $first['contact_id']) {
            // Starting a call isn't a call: open the modal so the agent logs what actually happened.
            $this->openCallModal($first['contact_id']);
        } elseif ($first['url']) {
            $this->redirect($first['url']);
        }
    }

    /**
     * Advance a sequence step for an enrolled contact.
     */
    public function advanceEnrollment(int $enrollmentId): void
    {
        /** @var SalesSequenceEnrollment $enrollment */
        $enrollment = SalesSequenceEnrollment::query()->with('sequence')->findOrFail($enrollmentId);

        $contact = $this->findContactForUpdate($enrollment->contact_id);
        OddenAuthorization::authorize('update', $enrollment);

        $enrollment->advanceStep();

        $contact->markContacted();
        if ($contact->lead_status === LeadStatus::New) {
            $contact->updateQuietly(['lead_status' => LeadStatus::InProgress]);
        }

        Notification::make()
            ->title('Sequence Step Advanced')
            ->body("Step marked complete for {$contact->full_name}.")
            ->success()
            ->send();
    }

    /**
     * Complete an activity / task.
     */
    public function completeActivity(int $activityId): void
    {
        /** @var Activity $activity */
        $activity = Activity::query()->findOrFail($activityId);

        OddenAuthorization::authorize('update', $activity);

        // Completing a task changes the record it belongs to, so that record must be in scope and updatable.
        $subjectResource = match (true) {
            $activity->subject instanceof Contact => ContactResource::class,
            $activity->subject instanceof Company => CompanyResource::class,
            $activity->subject instanceof Deal => DealResource::class,
            default => null,
        };

        if ($subjectResource !== null) {
            OddenAuthorization::findAndAuthorize($subjectResource, $activity->subject::class, $activity->subject->getKey(), 'update');
        }

        $activity->update([
            'status' => ActivityStatus::Completed,
            'completed_at' => now(),
        ]);

        Notification::make()
            ->title('Task Completed')
            ->body("Activity '{$activity->title}' marked done.")
            ->success()
            ->send();
    }

    /**
     * Log a quick prospecting touch (Call, Email, or LinkedIn).
     */
    public function logQuickTouch(int $contactId, string $type): void
    {
        $contact = $this->findContactForUpdate($contactId);

        $activityType = match ($type) {
            'call' => ActivityType::Call,
            'email' => ActivityType::Email,
            'linkedin' => ActivityType::LinkedIn,
            default => ActivityType::Task,
        };

        $contact->logActivity(
            type: $activityType,
            title: "Outbound {$activityType->label()} Touch",
            body: 'Completed prospecting outreach via Sales Cockpit.',
            status: ActivityStatus::Completed
        );

        $contact->markContacted();

        if ($contact->lead_status === LeadStatus::New) {
            $contact->updateQuietly([
                'lead_status' => LeadStatus::AttemptedContact,
            ]);
        }

        Notification::make()
            ->title('Touch Recorded')
            ->body("Logged {$activityType->label()} with {$contact->full_name} and updated contact status.")
            ->success()
            ->send();
    }

    public function openCallModal(int $contactId): void
    {
        $this->findContactForUpdate($contactId);

        $this->callContactId = $contactId;
        $this->callDisposition = 'connected';
        $this->callDurationMinutes = 5;
        $this->callNotes = '';
        $this->createFollowUpTask = false;
        $this->followUpTaskDate = Carbon::tomorrow()->toDateString();
        $this->followUpTaskTitle = 'Follow-up Call';
        $this->showCallModal = true;
    }

    public function closeCallModal(): void
    {
        $this->showCallModal = false;
        $this->callContactId = null;
    }

    public function saveCallLog(): void
    {
        if (! $this->callContactId) {
            return;
        }

        $contact = $this->findContactForUpdate($this->callContactId);

        $disposition = CallDisposition::tryFrom($this->callDisposition) ?? CallDisposition::Connected;

        $contact->logActivity(
            type: ActivityType::Call,
            title: "Outbound Call ({$disposition->label()})",
            body: $this->callNotes !== '' ? $this->callNotes : "Logged call outcome: {$disposition->label()}.",
            metadata: [
                'disposition' => $disposition->value,
                'duration_minutes' => $this->callDurationMinutes,
            ],
            status: ActivityStatus::Completed
        );

        $contact->markContacted();

        // Update lead status based on outcome
        if ($disposition === CallDisposition::Connected) {
            $contact->updateQuietly(['lead_status' => LeadStatus::Connected]);
        } elseif ($contact->lead_status === LeadStatus::New) {
            $contact->updateQuietly(['lead_status' => LeadStatus::AttemptedContact]);
        }

        // Schedule follow-up task if selected
        if ($this->createFollowUpTask && $this->followUpTaskDate) {
            $contact->logActivity(
                type: ActivityType::Task,
                title: $this->followUpTaskTitle !== '' ? $this->followUpTaskTitle : 'Follow-up Call',
                dueAt: Carbon::parse($this->followUpTaskDate)->startOfDay(),
                status: ActivityStatus::Pending
            );
        }

        Notification::make()
            ->title('Call Logged Successfully')
            ->body("Recorded {$disposition->label()} call with {$contact->full_name}.")
            ->success()
            ->send();

        $this->closeCallModal();
    }

    public function openMeetingModal(?int $contactId = null): void
    {
        if ($contactId !== null) {
            $this->findContactForUpdate($contactId);
        }

        $this->meetingContactId = $contactId;
        $this->meetingTitle = 'Discovery & Demo Call';
        $this->meetingDate = Carbon::tomorrow()->toDateString();
        $this->meetingTime = '10:00';
        $this->meetingDurationMinutes = 30;
        $this->meetingNotes = '';
        $this->showMeetingModal = true;
    }

    public function closeMeetingModal(): void
    {
        $this->showMeetingModal = false;
        $this->meetingContactId = null;
    }

    public function saveMeetingLog(): void
    {
        $dueAt = Carbon::today()->setTime(10, 0);
        if ($this->meetingDate && $this->meetingTime) {
            $dueAt = Carbon::parse("{$this->meetingDate} {$this->meetingTime}");
        }

        if ($this->meetingContactId) {
            $contact = $this->findContactForUpdate($this->meetingContactId);

            $contact->logActivity(
                type: ActivityType::Meeting,
                title: $this->meetingTitle,
                body: $this->meetingNotes,
                metadata: ['duration_minutes' => $this->meetingDurationMinutes],
                dueAt: $dueAt,
                status: ActivityStatus::Pending
            );

            if ($contact->lead_status === LeadStatus::New) {
                $contact->updateQuietly(['lead_status' => LeadStatus::InProgress]);
            }
        } else {
            OddenAuthorization::authorize('create', Activity::class);

            Activity::query()->create([
                'type' => ActivityType::Meeting,
                'title' => $this->meetingTitle,
                'body' => $this->meetingNotes,
                'metadata' => ['duration_minutes' => $this->meetingDurationMinutes],
                'due_at' => $dueAt,
                'status' => ActivityStatus::Pending,
                'creator_id' => OddenAuthorization::userId(),
            ]);
        }

        Notification::make()
            ->title('Meeting Scheduled')
            ->body("Added '{$this->meetingTitle}' to your schedule for {$dueAt->format('M j, g:i A')}.")
            ->success()
            ->send();

        $this->closeMeetingModal();
    }

    /**
     * Find a contact within the contact resource's query and authorize `update` on it (404 / 403 otherwise).
     */
    protected function findContactForUpdate(int $contactId): Contact
    {
        return OddenAuthorization::findAndAuthorize(ContactResource::class, Contact::class, $contactId, 'update');
    }

    protected function getInitials(string $name): string
    {
        $words = preg_split('/\s+/', trim($name));
        if (! $words || empty($words[0])) {
            return 'NA';
        }

        if (count($words) === 1) {
            return strtoupper(substr($words[0], 0, 2));
        }

        return strtoupper(substr($words[0], 0, 1).substr($words[count($words) - 1], 0, 1));
    }
}
