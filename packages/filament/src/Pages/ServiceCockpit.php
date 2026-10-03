<?php

declare(strict_types=1);

namespace Odden\Filament\Pages;

use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Support\UserModel;
use Odden\Filament\Pages\Concerns\AuthorizesPageAccess;
use Odden\Filament\Resources\TicketResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Service\Actions\DeflectTicketAction;
use Odden\Service\Actions\ReplyTicketAction;
use Odden\Service\Actions\ResolveTicketAction;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\CannedResponse;
use Odden\Service\Models\Ticket;
use UnitEnum;

class ServiceCockpit extends Page
{
    use AuthorizesPageAccess;

    protected static UnitEnum|string|null $navigationGroup = 'Service';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Lifebuoy;

    protected static ?string $navigationLabel = 'Support Cockpit';

    protected static ?string $title = 'Support Agent Workspace';

    protected string $view = 'odden-filament::pages.service-cockpit';

    public string $activeTab = 'triage';

    public ?int $selectedUserId = null;

    public string $search = '';

    public ?string $priorityFilter = null;

    public ?string $sourceFilter = null;

    public bool $showReplyModal = false;

    public ?int $replyTicketId = null;

    public string $replyBody = '';

    public string $replyStatus = 'waiting_on_customer';

    public bool $replyIsInternalNote = false;

    public ?int $selectedCannedResponseId = null;

    public bool $showResolveModal = false;

    public ?int $resolveTicketId = null;

    public string $resolveNote = '';

    /**
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    protected static function getAuthorizationResources(): array
    {
        return [
            TicketResource::class,
        ];
    }

    public function mount(): void
    {
        $this->selectedUserId = (int) OddenAuthorization::userId();
    }

    public function setActiveTab(string $tab): void
    {
        if (in_array($tab, ['triage', 'my_tickets', 'sla_watch', 'all'], true)) {
            $this->activeTab = $tab;
        }
    }

    public function setPriorityFilter(?string $priority): void
    {
        $this->priorityFilter = $priority ?: null;
    }

    public function setSourceFilter(?string $source): void
    {
        $this->sourceFilter = $source ?: null;
    }

    public function setSelectedUser(int|string|null $userId = null): void
    {
        $this->selectedUserId = ! empty($userId) ? (int) $userId : null;
    }

    public function getOpenTicketsCountProperty(): int
    {
        return OddenAuthorization::query(TicketResource::class, Ticket::class)
            ->whereIn('status', [TicketStatus::New->value, TicketStatus::Open->value, TicketStatus::WaitingOnAgent->value])
            ->count();
    }

    public function getUnassignedTicketsCountProperty(): int
    {
        return OddenAuthorization::query(TicketResource::class, Ticket::class)
            ->whereIn('status', [TicketStatus::New->value, TicketStatus::Open->value, TicketStatus::WaitingOnAgent->value])
            ->whereNull('owner_id')
            ->count();
    }

    public function getMyActiveCountProperty(): int
    {
        $userId = $this->selectedUserId ?? OddenAuthorization::userId();

        return OddenAuthorization::query(TicketResource::class, Ticket::class)
            ->whereIn('status', [TicketStatus::New->value, TicketStatus::Open->value, TicketStatus::WaitingOnAgent->value, TicketStatus::WaitingOnCustomer->value])
            ->where('owner_id', $userId)
            ->count();
    }

    public function getSlaAtRiskCountProperty(): int
    {
        $oneHourFromNow = now()->addHour();

        return OddenAuthorization::query(TicketResource::class, Ticket::class)
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
            ->where(function (Builder $query) use ($oneHourFromNow): void {
                $query->where('is_sla_response_breached', true)
                    ->orWhere('is_sla_resolution_breached', true)
                    ->orWhere(function (Builder $q) use ($oneHourFromNow): void {
                        $q->whereNull('first_responded_at')
                            ->whereNotNull('first_response_due_at')
                            ->where('first_response_due_at', '<=', $oneHourFromNow);
                    })
                    ->orWhere(function (Builder $q) use ($oneHourFromNow): void {
                        $q->whereNull('resolved_at')
                            ->whereNotNull('resolution_due_at')
                            ->where('resolution_due_at', '<=', $oneHourFromNow);
                    });
            })
            ->count();
    }

    public function getAverageCsatRatingProperty(): ?float
    {
        $avg = OddenAuthorization::query(TicketResource::class, Ticket::class)->whereNotNull('csat_rating')->avg('csat_rating');

        return $avg !== null ? round((float) $avg, 1) : null;
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function getTriageTicketsProperty(): Collection
    {
        $query = OddenAuthorization::query(TicketResource::class, Ticket::class)
            ->whereIn('status', [TicketStatus::New->value, TicketStatus::Open->value, TicketStatus::WaitingOnAgent->value])
            ->whereNull('owner_id')
            ->with(['contact', 'company', 'slaPolicy'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('created_at', 'asc');

        $this->applyFilters($query);

        return $query->take(50)->get();
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function getMyTicketsProperty(): Collection
    {
        $userId = $this->selectedUserId ?? OddenAuthorization::userId();

        $query = OddenAuthorization::query(TicketResource::class, Ticket::class)
            ->whereIn('status', [TicketStatus::New->value, TicketStatus::Open->value, TicketStatus::WaitingOnAgent->value, TicketStatus::WaitingOnCustomer->value])
            ->where('owner_id', $userId)
            ->with(['contact', 'company', 'slaPolicy'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('updated_at', 'desc');

        $this->applyFilters($query);

        return $query->take(50)->get();
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function getSlaWatchTicketsProperty(): Collection
    {
        $twoHoursFromNow = now()->addHours(2);

        $query = OddenAuthorization::query(TicketResource::class, Ticket::class)
            ->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value])
            ->where(function (Builder $q) use ($twoHoursFromNow): void {
                $q->where('is_sla_response_breached', true)
                    ->orWhere('is_sla_resolution_breached', true)
                    ->orWhere(function (Builder $sub) use ($twoHoursFromNow): void {
                        $sub->whereNull('first_responded_at')
                            ->whereNotNull('first_response_due_at')
                            ->where('first_response_due_at', '<=', $twoHoursFromNow);
                    })
                    ->orWhere(function (Builder $sub) use ($twoHoursFromNow): void {
                        $sub->whereNull('resolved_at')
                            ->whereNotNull('resolution_due_at')
                            ->where('resolution_due_at', '<=', $twoHoursFromNow);
                    });
            })
            ->with(['contact', 'company', 'owner', 'slaPolicy'])
            ->orderByRaw('CASE WHEN is_sla_response_breached = 1 OR is_sla_resolution_breached = 1 THEN 1 ELSE 2 END')
            ->orderBy('first_response_due_at', 'asc');

        $this->applyFilters($query);

        return $query->take(50)->get();
    }

    /**
     * @return Collection<int, Ticket>
     */
    public function getAllTicketsProperty(): Collection
    {
        $query = OddenAuthorization::query(TicketResource::class, Ticket::class)
            ->whereNotIn('status', [TicketStatus::Closed->value])
            ->with(['contact', 'company', 'owner', 'slaPolicy'])
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->orderBy('created_at', 'desc');

        $this->applyFilters($query);

        return $query->take(50)->get();
    }

    /**
     * @return Collection<int, CannedResponse>
     */
    public function getCannedResponsesProperty(): Collection
    {
        return $this->cannedResponsesQuery()
            ->orderBy('title')
            ->get();
    }

    /**
     * @return Collection<int, Model>
     */
    public function getUsersProperty(): Collection
    {
        return UserModel::query()->orderBy('name')->get();
    }

    public function claimTicket(int $ticketId): void
    {
        $ticket = $this->findTicketForUpdate($ticketId);

        $userId = OddenAuthorization::userId();

        $updates = ['owner_id' => $userId];
        if ($ticket->status === TicketStatus::New) {
            $updates['status'] = TicketStatus::Open;
        }

        $ticket->update($updates);

        Notification::make()
            ->title('Ticket Claimed')
            ->body("You claimed ticket {$ticket->ticket_number}.")
            ->success()
            ->send();
    }

    public function claimAndOpen(int $ticketId): mixed
    {
        $this->claimTicket($ticketId);

        return redirect()->to(TicketResource::getUrl('edit', ['record' => $ticketId]));
    }

    public function openReplyModal(int $ticketId): void
    {
        $this->findTicketForUpdate($ticketId);

        $this->replyTicketId = $ticketId;
        $this->replyBody = '';
        $this->replyStatus = 'waiting_on_customer';
        $this->replyIsInternalNote = false;
        $this->selectedCannedResponseId = null;
        $this->showReplyModal = true;
    }

    public function insertCannedResponse(int|string|null $id = null): void
    {
        if (empty($id)) {
            return;
        }

        $id = (int) $id;

        /** @var CannedResponse|null $canned */
        $canned = $this->cannedResponsesQuery()->find($id);
        if ($canned !== null) {
            $this->replyBody = empty($this->replyBody)
                ? $canned->content
                : $this->replyBody."\n\n".$canned->content;
        }
    }

    /**
     * AI Copilot: Smart knowledge base suggestions based on ticket inquiry context.
     *
     * @return \Illuminate\Support\Collection<int, array{id: int, title: string, slug: string, category: string, excerpt: string, helpful_count: int, deflections_count: int, url: string}>
     */
    public function getSuggestedArticlesProperty(): \Illuminate\Support\Collection
    {
        if ($this->replyTicketId === null) {
            return collect();
        }

        /** @var Ticket|null $ticket */
        $ticket = OddenAuthorization::query(TicketResource::class, Ticket::class)->find($this->replyTicketId);
        if ($ticket === null) {
            return collect();
        }

        return app(DeflectTicketAction::class)->execute($ticket->subject.' '.($ticket->description ?? ''), 3);
    }

    public function insertArticleLink(string $title, string $url): void
    {
        $linkMarkdown = "For step-by-step instructions, see our Help Center guide: [{$title}]({$url})";
        $this->replyBody = empty($this->replyBody)
            ? $linkMarkdown
            : $this->replyBody."\n\n".$linkMarkdown;
    }

    public function sendQuickReply(): void
    {
        if ($this->replyTicketId === null || trim($this->replyBody) === '') {
            Notification::make()->title('Reply message cannot be empty')->warning()->send();

            return;
        }

        $ticket = $this->findTicketForUpdate($this->replyTicketId);

        // The same actions as the ticket resource's Add Reply and Resolve, so the customer is
        // emailed and their timeline is updated.
        app(ReplyTicketAction::class)->execute(
            ticket: $ticket,
            body: $this->replyBody,
            senderType: MessageSenderType::Agent,
            user: auth()->user(),
            isInternalNote: $this->replyIsInternalNote,
        );

        if (! $this->replyIsInternalNote && in_array($this->replyStatus, ['open', 'waiting_on_customer', 'resolved'], true)) {
            if ($this->replyStatus === 'resolved') {
                app(ResolveTicketAction::class)->execute($ticket);
            } else {
                $ticket->update(['status' => $this->replyStatus]);
            }
        }

        Notification::make()
            ->title($this->replyIsInternalNote ? 'Internal Note Added' : 'Reply Sent')
            ->body("Response recorded for {$ticket->ticket_number}.")
            ->success()
            ->send();

        $this->closeReplyModal();
    }

    public function closeReplyModal(): void
    {
        $this->showReplyModal = false;
        $this->replyTicketId = null;
        $this->replyBody = '';
        $this->selectedCannedResponseId = null;
    }

    public function openResolveModal(int $ticketId): void
    {
        $this->findTicketForUpdate($ticketId);

        $this->resolveTicketId = $ticketId;
        $this->resolveNote = '';
        $this->showResolveModal = true;
    }

    public function quickResolveTicket(): void
    {
        if ($this->resolveTicketId === null) {
            return;
        }

        $ticket = $this->findTicketForUpdate($this->resolveTicketId);

        app(ResolveTicketAction::class)->execute($ticket, trim($this->resolveNote) !== '' ? $this->resolveNote : null);

        Notification::make()
            ->title('Ticket Resolved')
            ->body("Ticket {$ticket->ticket_number} marked as resolved.")
            ->success()
            ->send();

        $this->closeResolveModal();
    }

    public function closeResolveModal(): void
    {
        $this->showResolveModal = false;
        $this->resolveTicketId = null;
        $this->resolveNote = '';
    }

    public function getActiveTicketForReply(): ?Ticket
    {
        if ($this->replyTicketId === null) {
            return null;
        }

        return OddenAuthorization::query(TicketResource::class, Ticket::class)->with(['contact', 'company'])->find($this->replyTicketId);
    }

    public function getActiveTicketForResolve(): ?Ticket
    {
        if ($this->resolveTicketId === null) {
            return null;
        }

        return OddenAuthorization::query(TicketResource::class, Ticket::class)->with(['contact', 'company'])->find($this->resolveTicketId);
    }

    /**
     * Shared canned responses plus the current agent's own.
     *
     * @return Builder<CannedResponse>
     */
    protected function cannedResponsesQuery(): Builder
    {
        $userId = OddenAuthorization::userId();

        return CannedResponse::query()
            ->where(function (Builder $query) use ($userId): void {
                $query->where('is_shared', true)
                    ->orWhere('user_id', $userId);
            });
    }

    /**
     * Find a ticket within the resource's query and authorize `update` on it (404 / 403 otherwise).
     */
    protected function findTicketForUpdate(int $ticketId): Ticket
    {
        return OddenAuthorization::findAndAuthorize(TicketResource::class, Ticket::class, $ticketId, 'update');
    }

    /**
     * @param  Builder<Ticket>  $query
     */
    protected function applyFilters(Builder $query): void
    {
        if (trim($this->search) !== '') {
            $search = '%'.trim($this->search).'%';
            $query->where(function (Builder $q) use ($search): void {
                $q->where('ticket_number', 'like', $search)
                    ->orWhere('subject', 'like', $search)
                    ->orWhereHas('contact', function (Builder $cq) use ($search): void {
                        $cq->where('first_name', 'like', $search)
                            ->orWhere('last_name', 'like', $search)
                            ->orWhere('email', 'like', $search);
                    });
            });
        }

        if ($this->priorityFilter !== null) {
            $query->where('priority', $this->priorityFilter);
        }

        if ($this->sourceFilter !== null) {
            $query->where('source', $this->sourceFilter);
        }
    }
}
