<?php

declare(strict_types=1);

namespace Odden\Service\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Core\Traits\HasCustomProperties;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;

/**
 * @property int $id
 * @property string $ticket_number
 * @property string $portal_token
 * @property string $subject
 * @property string|null $description
 * @property TicketStatus $status
 * @property TicketPriority $priority
 * @property TicketSource $source
 * @property int|null $contact_id
 * @property int|null $company_id
 * @property int|null $owner_id
 * @property int|null $sla_policy_id
 * @property int|null $team_id
 * @property int|null $merged_into_ticket_id
 * @property CarbonInterface|null $merged_at
 * @property CarbonInterface|null $first_response_due_at
 * @property CarbonInterface|null $first_responded_at
 * @property CarbonInterface|null $resolution_due_at
 * @property CarbonInterface|null $resolved_at
 * @property CarbonInterface|null $closed_at
 * @property bool $is_sla_response_breached
 * @property bool $is_sla_resolution_breached
 * @property int|null $csat_rating
 * @property string|null $csat_comment
 * @property array<string, mixed>|null $properties
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 * @property-read Contact|null $contact
 * @property-read Company|null $company
 * @property-read Model|null $owner
 * @property-read SlaPolicy|null $slaPolicy
 * @property-read Ticket|null $mergedInto
 * @property-read Collection<int, Ticket> $mergedTickets
 * @property-read Collection<int, TicketMessage> $messages
 */
class Ticket extends Model
{
    use HasCustomProperties;
    use SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'ticket_number',
        'portal_token',
        'subject',
        'description',
        'status',
        'priority',
        'source',
        'contact_id',
        'company_id',
        'owner_id',
        'sla_policy_id',
        'team_id',
        'merged_into_ticket_id',
        'merged_at',
        'first_response_due_at',
        'first_responded_at',
        'resolution_due_at',
        'resolved_at',
        'closed_at',
        'is_sla_response_breached',
        'is_sla_resolution_breached',
        'csat_rating',
        'csat_comment',
        'properties',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'new',
        'priority' => 'medium',
        'source' => 'web_portal',
    ];

    /**
     * Get the table associated with the model.
     */
    public function getTable(): string
    {
        return config('odden-service.tables.tickets', 'odden_service_tickets');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'source' => TicketSource::class,
            'first_response_due_at' => 'datetime',
            'first_responded_at' => 'datetime',
            'resolution_due_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'merged_at' => 'datetime',
            'is_sla_response_breached' => 'boolean',
            'is_sla_resolution_breached' => 'boolean',
            'csat_rating' => 'integer',
            'properties' => 'array',
        ];
    }

    /**
     * Bootstrap the model and its events.
     */
    protected static function booted(): void
    {
        static::creating(function (self $ticket): void {
            if (empty($ticket->ticket_number)) {
                $prefix = (string) config('odden-service.defaults.prefix', 'TICK');
                $ticket->ticket_number = $prefix.'-'.now()->format('Y').'-'.strtoupper(Str::random(5));
            }

            if (empty($ticket->portal_token)) {
                $ticket->portal_token = Str::random(40);
            }

            if ($ticket->sla_policy_id === null) {
                /** @var SlaPolicy|null $defaultPolicy */
                $defaultPolicy = SlaPolicy::query()->where('is_default', true)->where('is_active', true)->first();
                if ($defaultPolicy !== null) {
                    $ticket->sla_policy_id = $defaultPolicy->id;
                }
            }

            if ($ticket->sla_policy_id !== null && $ticket->first_response_due_at === null) {
                $ticket->recalculateSlaDueDates();
            }
        });

        static::updating(function (self $ticket): void {
            if ($ticket->isDirty('priority') && ! $ticket->isDirty(['first_response_due_at', 'resolution_due_at'])) {
                $ticket->recalculateSlaDueDates();
            }
        });
    }

    /**
     * Recompute the SLA due dates from the governing policy and the current priority.
     *
     * The SLA clock starts when the ticket is created, so a priority change moves the targets
     * relative to that moment. A due date is left alone once its target has been met (the
     * ticket has had a first response, or has been resolved).
     */
    public function recalculateSlaDueDates(): void
    {
        if ($this->sla_policy_id === null) {
            return;
        }

        $policy = $this->slaPolicy ?? SlaPolicy::find($this->sla_policy_id);
        if (! $policy instanceof SlaPolicy) {
            return;
        }

        $from = $this->created_at ?? now();

        if ($this->first_responded_at === null) {
            $this->first_response_due_at = $policy->calculateDueTime($from, $policy->getFirstResponseMinutesFor($this->priority));
        }

        if ($this->resolved_at === null) {
            $this->resolution_due_at = $policy->calculateDueTime($from, $policy->getResolutionMinutesFor($this->priority));
        }
    }

    /**
     * Associated customer contact.
     *
     * @return BelongsTo<Contact, $this>
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'contact_id');
    }

    /**
     * Associated customer company.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }

    /**
     * Assigned support agent / rep.
     *
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        $userModel = UserModel::className();

        return $this->belongsTo($userModel, 'owner_id');
    }

    /**
     * Governing SLA policy.
     *
     * @return BelongsTo<SlaPolicy, $this>
     */
    public function slaPolicy(): BelongsTo
    {
        return $this->belongsTo(SlaPolicy::class, 'sla_policy_id');
    }

    /**
     * Primary ticket this duplicate ticket was merged into.
     *
     * @return BelongsTo<Ticket, $this>
     */
    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_ticket_id');
    }

    /**
     * Secondary tickets merged into this primary ticket.
     *
     * @return HasMany<Ticket, $this>
     */
    public function mergedTickets(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_ticket_id');
    }

    /**
     * Ticket conversation thread messages.
     *
     * @return HasMany<TicketMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class, 'ticket_id')->orderBy('created_at', 'asc');
    }

    /**
     * Add a message / reply / note to this ticket.
     *
     * @param  array<int, mixed>|null  $attachments
     */
    public function addMessage(
        string $body,
        MessageSenderType $senderType = MessageSenderType::Agent,
        ?int $userId = null,
        ?int $contactId = null,
        bool $isInternalNote = false,
        ?array $attachments = null
    ): TicketMessage {
        $message = $this->messages()->create([
            'body' => $body,
            'sender_type' => $senderType->value,
            'user_id' => $userId,
            'contact_id' => $contactId,
            'is_internal_note' => $isInternalNote,
            'attachments' => $attachments,
        ]);

        // If this is the first agent public response, mark first_responded_at
        if (! $isInternalNote && $senderType === MessageSenderType::Agent && $this->first_responded_at === null) {
            $now = now();
            $breached = $this->first_response_due_at !== null && $now->isAfter($this->first_response_due_at);

            $updates = [
                'first_responded_at' => $now,
                'is_sla_response_breached' => $breached,
            ];

            if (! $this->status->isClosed()) {
                $updates['status'] = TicketStatus::WaitingOnCustomer;
            }

            $this->updateQuietly($updates);
        } elseif (! $isInternalNote && $senderType === MessageSenderType::Customer) {
            // Customer replied, so the ball is with the agents: the ticket becomes WaitingOnAgent.
            // Resolved and Closed tickets are reopened that way when odden-service.reopen_on_customer_reply
            // is on (the default). A ticket merged into another stays closed; replies to it belong
            // on mergeTarget().
            if ($this->status->isClosed()) {
                if ($this->merged_into_ticket_id === null && (bool) config('odden-service.reopen_on_customer_reply', true)) {
                    $this->updateQuietly([
                        'status' => TicketStatus::WaitingOnAgent,
                        'resolved_at' => null,
                        'closed_at' => null,
                    ]);
                }
            } elseif ($this->status !== TicketStatus::WaitingOnAgent) {
                $this->updateQuietly([
                    'status' => TicketStatus::WaitingOnAgent,
                ]);
                $this->status = TicketStatus::WaitingOnAgent;
            }
        }

        return $message;
    }

    /**
     * Whether a customer holding this ticket's portal token may reply into the ticket it was merged into.
     *
     * Only when the token never left the customer's mailbox: tickets created from an inbound
     * email, by phone, or through the API reach their contact only by email. Portal and chat
     * tickets hand the token straight to whoever filled in the form, and that email address is
     * never verified, so anyone could open one in another customer's name; their token stays
     * bound to the ticket itself and never reaches (or reveals) the primary ticket.
     */
    public function portalTokenFollowsMerge(): bool
    {
        return ! in_array($this->source, [TicketSource::WebPortal, TicketSource::Chat], true);
    }

    /**
     * The ticket that conversation on this one now belongs to.
     *
     * Follows merged_into_ticket_id to the end of the merge chain (a merged ticket's primary
     * may itself have been merged since). Returns this ticket when it was never merged, and
     * stops early at a primary that no longer exists or on a cycle.
     */
    public function mergeTarget(): self
    {
        $target = $this;
        $seen = [$this->id => true];

        while ($target->merged_into_ticket_id !== null) {
            /** @var Ticket|null $next */
            $next = self::query()->find($target->merged_into_ticket_id);

            if ($next === null || isset($seen[$next->id])) {
                break;
            }

            $seen[$next->id] = true;
            $target = $next;
        }

        return $target;
    }

    /**
     * Mark the ticket as resolved.
     */
    public function resolve(?string $resolutionNote = null): self
    {
        if (! empty($resolutionNote)) {
            $this->addMessage($resolutionNote, MessageSenderType::Agent, auth()->id() !== null ? (int) auth()->id() : null, null, false);
        }

        $now = now();
        $breached = $this->resolution_due_at !== null && $now->isAfter($this->resolution_due_at);

        $this->update([
            'status' => TicketStatus::Resolved,
            'resolved_at' => $now,
            'is_sla_resolution_breached' => $breached,
        ]);

        return $this;
    }

    /**
     * Close the ticket permanently.
     */
    public function close(): self
    {
        $this->update([
            'status' => TicketStatus::Closed,
            'closed_at' => now(),
        ]);

        return $this;
    }

    /**
     * Reopen an already resolved or closed ticket.
     */
    public function reopen(): self
    {
        $this->update([
            'status' => TicketStatus::Open,
            'resolved_at' => null,
            'closed_at' => null,
        ]);

        return $this;
    }

    /**
     * Whether the first response SLA has been breached.
     */
    public function isFirstResponseBreached(): bool
    {
        if ($this->is_sla_response_breached) {
            return true;
        }

        if ($this->first_responded_at !== null) {
            return false;
        }

        return $this->first_response_due_at !== null && now()->isAfter($this->first_response_due_at);
    }

    /**
     * Whether the resolution SLA has been breached.
     */
    public function isResolutionBreached(): bool
    {
        if ($this->is_sla_resolution_breached) {
            return true;
        }

        if ($this->resolved_at !== null) {
            return false;
        }

        return $this->resolution_due_at !== null && now()->isAfter($this->resolution_due_at);
    }

    /**
     * Get the public customer tracking URL for this ticket.
     */
    public function getPortalUrl(): string
    {
        return route('odden.support.show', $this->portal_token);
    }

    /**
     * Get the customer CSAT feedback survey URL for this ticket.
     */
    public function getCsatUrl(): string
    {
        return route('odden.support.rate', $this->portal_token);
    }
}
