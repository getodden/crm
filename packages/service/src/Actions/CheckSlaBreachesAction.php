<?php

declare(strict_types=1);

namespace Odden\Service\Actions;

use Illuminate\Database\Eloquent\Collection;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\Ticket;
use Odden\Service\Notifications\SlaBreachAlertNotification;

class CheckSlaBreachesAction
{
    /**
     * Inspect open tickets and flag SLA breaches.
     *
     * @return array{response_breaches: int, resolution_breaches: int}
     */
    public function execute(): array
    {
        $now = now();
        $responseBreachesCount = 0;
        $resolutionBreachesCount = 0;

        // A ticket waiting on the customer is not late because of the agents, so its clock does not run.
        $clockStopped = [TicketStatus::Resolved->value, TicketStatus::Closed->value, TicketStatus::WaitingOnCustomer->value];

        // Tickets already escalated in this run: escalating recalculates their due dates, which could otherwise
        // make the same ticket show up again below and be escalated a second time.
        $handled = [];

        // 1. Check first response breaches (unresponded tickets past due date)
        /** @var Collection<int, Ticket> $unrespondedTickets */
        $unrespondedTickets = Ticket::query()
            ->whereNull('first_responded_at')
            ->where('is_sla_response_breached', false)
            ->whereNotNull('first_response_due_at')
            ->where('first_response_due_at', '<', $now)
            ->whereNotIn('status', $clockStopped)
            ->get();

        foreach ($unrespondedTickets as $ticket) {
            // Claim the breach with a conditional update: of two overlapping runs only one sees it succeed.
            if (! $this->claimBreach($ticket, 'is_sla_response_breached')) {
                continue;
            }

            $handled[] = $ticket->id;
            $responseBreachesCount++;

            if ($ticket->owner !== null && method_exists($ticket->owner, 'notify')) {
                $ticket->owner->notify(new SlaBreachAlertNotification($ticket, 'first_response'));
            } else {
                $this->escalateUnassignedTicket($ticket, 'first_response');
            }
        }

        // 2. Check resolution breaches (unresolved tickets past due date)
        /** @var Collection<int, Ticket> $unresolvedTickets */
        $unresolvedTickets = Ticket::query()
            ->whereNull('resolved_at')
            ->where('is_sla_resolution_breached', false)
            ->whereNotNull('resolution_due_at')
            ->where('resolution_due_at', '<', $now)
            ->whereNotIn('status', $clockStopped)
            ->whereNotIn('id', $handled)
            ->get();

        foreach ($unresolvedTickets as $ticket) {
            if (! $this->claimBreach($ticket, 'is_sla_resolution_breached')) {
                continue;
            }

            $resolutionBreachesCount++;

            if ($ticket->owner !== null && method_exists($ticket->owner, 'notify')) {
                $ticket->owner->notify(new SlaBreachAlertNotification($ticket, 'resolution'));
            } else {
                $this->escalateUnassignedTicket($ticket, 'resolution');
            }
        }

        return [
            'response_breaches' => $responseBreachesCount,
            'resolution_breaches' => $resolutionBreachesCount,
        ];
    }

    /**
     * Set a breach flag only if it is still unset, and say whether this call was the one that set it.
     */
    protected function claimBreach(Ticket $ticket, string $flag): bool
    {
        return Ticket::query()->whereKey($ticket->getKey())->where($flag, false)->update([$flag => true]) === 1;
    }

    /**
     * Automatically escalate priority and log internal alert when an unassigned ticket breaches SLA.
     */
    protected function escalateUnassignedTicket(Ticket $ticket, string $type): void
    {
        $newPriority = match ($ticket->priority) {
            TicketPriority::Low => TicketPriority::Medium,
            TicketPriority::Medium => TicketPriority::High,
            TicketPriority::High, TicketPriority::Urgent => TicketPriority::Urgent,
        };

        if ($newPriority !== $ticket->priority) {
            // Quiet save: the update hook doesn't run, so recalculate the due dates here.
            $ticket->priority = $newPriority;
            $ticket->recalculateSlaDueDates();
            $ticket->saveQuietly();
        }

        $typeLabel = $type === 'first_response' ? 'first response' : 'resolution';

        $ticket->messages()->create([
            'body' => "⚠️ SLA Breach Alert: Ticket breached {$typeLabel} SLA while unassigned. Priority escalated to {$newPriority->label()}.",
            'sender_type' => MessageSenderType::System->value,
            'is_internal_note' => true,
        ]);
    }
}
