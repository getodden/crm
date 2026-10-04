<?php

declare(strict_types=1);

namespace Odden\Service\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Odden\Core\Support\ContactLookup;
use Odden\Service\Actions\CreateTicketAction;
use Odden\Service\Actions\ReplyTicketAction;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Models\Ticket;

class SupportPortalController extends Controller
{
    /**
     * Show ticket submission form.
     */
    public function create(): View
    {
        return view('odden-service::portal.create');
    }

    /**
     * Submit a new customer support ticket.
     */
    public function store(Request $request, CreateTicketAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'priority' => ['required', 'string', 'in:low,medium,high,urgent'],
            'description' => ['required', 'string'],
        ]);

        // Split name into first and last name
        $nameParts = explode(' ', trim($validated['name']), 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $contact = ContactLookup::findOrCreate($validated['email'], [
            'first_name' => $firstName,
            'last_name' => $lastName,
        ]);

        $priority = TicketPriority::tryFrom($validated['priority']) ?? TicketPriority::Medium;

        $ticket = $action->execute(
            subject: $validated['subject'],
            description: $validated['description'],
            priority: $priority,
            source: TicketSource::WebPortal,
            contact: $contact
        );

        return redirect()
            ->route('odden.support.show', ['token' => $ticket->portal_token])
            ->with('status', 'Your support ticket has been received. Our team will review it shortly.');
    }

    /**
     * View customer ticket status and conversation thread.
     */
    public function show(string $token): View
    {
        /** @var Ticket $ticket */
        $ticket = Ticket::query()
            ->where('portal_token', $token)
            ->with(['messages.user', 'messages.contact', 'contact', 'company'])
            ->firstOrFail();

        return view('odden-service::portal.show', [
            'ticket' => $ticket,
        ]);
    }

    /**
     * Post a customer reply to the ticket thread.
     *
     * The page only ever shows the token's own ticket. A reply to a merged ticket is posted on
     * its primary only when the token reached the customer by email (Ticket::portalTokenFollowsMerge());
     * otherwise it is refused.
     */
    public function reply(Request $request, string $token, ReplyTicketAction $action): RedirectResponse
    {
        /** @var Ticket $ticket */
        $ticket = Ticket::query()
            ->where('portal_token', $token)
            ->firstOrFail();

        $validated = $request->validate([
            'body' => ['required', 'string'],
        ]);

        // A merged portal or chat ticket's token came from an unverified form, so it may not
        // reach the primary ticket (which can hold another customer's thread). See
        // Ticket::portalTokenFollowsMerge().
        if ($ticket->merged_into_ticket_id !== null && ! $ticket->portalTokenFollowsMerge()) {
            return back()->withErrors([
                'body' => 'This ticket was merged into another ticket and no longer takes replies here. Please reply to the latest email from our support team.',
            ]);
        }

        // Lands on the primary ticket if this one was merged, and reopens a resolved/closed ticket.
        $message = $action->execute(
            ticket: $ticket,
            body: $validated['body'],
            senderType: MessageSenderType::Customer,
            user: null,
            contact: $ticket->contact,
            isInternalNote: false
        );

        $status = $message->ticket_id === $ticket->id
            ? 'Your reply has been posted to the ticket.'
            : "This ticket was merged into ticket #{$message->ticket->ticket_number}. Your reply has been posted there and our team will follow up.";

        return back()->with('status', $status);
    }

    /**
     * Show CSAT rating survey form.
     */
    public function rate(string $token): View
    {
        /** @var Ticket $ticket */
        $ticket = Ticket::query()
            ->where('portal_token', $token)
            ->firstOrFail();

        return view('odden-service::portal.rate', [
            'ticket' => $ticket,
        ]);
    }

    /**
     * Save CSAT rating feedback.
     */
    public function submitRating(Request $request, string $token): RedirectResponse
    {
        /** @var Ticket $ticket */
        $ticket = Ticket::query()
            ->where('portal_token', $token)
            ->firstOrFail();

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);

        // A rating is for a finished ticket, given once: otherwise the link holder could rewrite it and add a
        // service-recovery note and task for every submission.
        if (! $ticket->status->isClosed()) {
            return redirect()
                ->route('odden.support.show', ['token' => $ticket->portal_token])
                ->withErrors(['rating' => 'You can rate this ticket once it has been resolved.']);
        }

        if ($ticket->csat_rating !== null) {
            return redirect()
                ->route('odden.support.show', ['token' => $ticket->portal_token])
                ->with('status', 'Thank you! You have already rated this ticket.');
        }

        $rating = (int) $validated['rating'];
        $comment = isset($validated['comment']) && $validated['comment'] !== '' ? (string) $validated['comment'] : null;

        $ticket->update([
            'csat_rating' => $rating,
            'csat_comment' => $comment,
        ]);

        // If dissatisfied customer feedback (1 or 2 stars), escalate for service recovery
        if ($rating <= 2) {
            $commentText = $comment !== null ? " Comment: \"{$comment}\"" : '';

            $ticket->messages()->create([
                'body' => "⚠️ Negative CSAT rating ({$rating}/5 stars) received from customer.{$commentText} Supervisor review recommended.",
                'sender_type' => MessageSenderType::System->value,
                'is_internal_note' => true,
            ]);

            if ($ticket->contact !== null) {
                $ticket->contact->logTask(
                    title: "CSAT Service Recovery: Ticket #{$ticket->ticket_number} ({$rating}/5 stars)",
                    dueAt: now()->addHours(24),
                    body: "Customer submitted an unsatisfied CSAT rating for ticket '{$ticket->subject}'.{$commentText} Reach out for service recovery."
                );
            }
        }

        return redirect()
            ->route('odden.support.show', ['token' => $ticket->portal_token])
            ->with('status', 'Thank you! Your feedback has been recorded.');
    }
}
