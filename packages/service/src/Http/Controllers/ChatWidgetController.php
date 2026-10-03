<?php

declare(strict_types=1);

namespace Odden\Service\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Odden\Core\Models\Company;
use Odden\Core\Support\ContactLookup;
use Odden\Service\Actions\CreateTicketAction;
use Odden\Service\Actions\ReplyTicketAction;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Models\Ticket;
use Odden\Service\Models\TicketMessage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ChatWidgetController extends Controller
{
    /**
     * Shown in place of the thread once the chat's ticket was merged into another ticket.
     */
    public const string MERGED_NOTICE = 'This conversation has moved to another support ticket. Please check your email for updates from our support team and reply there.';

    /**
     * Start a new live support chat session from the embedded messenger.
     *
     * The ticket is created by CreateTicketAction like every other channel: routing rules run,
     * and a task is logged on the contact's timeline. The confirmation email (with the portal
     * link) is only sent when odden-service.chat.confirmation_email is true: this endpoint is
     * public and never verifies the email address, so by default it emails nobody.
     */
    public function start(Request $request, CreateTicketAction $createAction): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string'],
            'company' => ['nullable', 'string', 'max:255'],
        ]);

        $nameParts = explode(' ', trim($validated['name']), 2);
        $firstName = $nameParts[0];
        $lastName = $nameParts[1] ?? '';

        $contact = ContactLookup::findOrCreate($validated['email'], [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'lifecycle_stage' => 'customer',
        ]);

        $company = null;
        if (! empty($validated['company'])) {
            /** @var Company $company */
            $company = Company::query()->firstOrCreate(
                ['name' => trim($validated['company'])],
                ['lifecycle_stage' => 'customer']
            );
            if (! $contact->isAssociatedWith($company)) {
                $contact->associateWith($company);
            }
        }

        // Seeds the customer's opening message from the description.
        $ticket = $createAction->execute(
            subject: "Live Chat inquiry from {$contact->full_name}",
            description: $validated['message'],
            priority: TicketPriority::Medium,
            source: TicketSource::Chat,
            contact: $contact,
            company: $company,
            notifyContact: (bool) config('odden-service.chat.confirmation_email', false),
        );

        // Automated welcoming response from support team
        $ticket->addMessage(
            body: "Hi {$contact->first_name}! 👋 Thanks for reaching out to support. A member of our team has received your message and will reply here momentarily.",
            senderType: MessageSenderType::System,
        );

        return response()->json([
            'success' => true,
            'token' => $ticket->portal_token,
            'ticket_number' => $ticket->ticket_number,
            'messages' => $this->formatMessages($ticket),
        ], 201);
    }

    /**
     * Send a customer follow-up message in an ongoing chat session.
     *
     * Goes through ReplyTicketAction, so a resolved or closed ticket reopens when
     * odden-service.reopen_on_customer_reply is on. A chat session never follows a merge: the
     * chat's email address was never verified, so once its ticket is merged the session is
     * read-only and the customer is pointed at their email (409, merged: true).
     */
    public function message(Request $request, string $token, ReplyTicketAction $replyAction): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->where('portal_token', $token)->first();

        if ($ticket === null) {
            return response()->json(['error' => 'Chat session not found.'], 404);
        }

        if ($ticket->merged_into_ticket_id !== null) {
            return response()->json([
                'success' => false,
                'merged' => true,
                'error' => self::MERGED_NOTICE,
                'notice' => self::MERGED_NOTICE,
                'messages' => $this->formatMessages($ticket),
            ], 409);
        }

        $replyAction->execute(
            ticket: $ticket,
            body: $validated['message'],
            senderType: MessageSenderType::Customer,
            user: null,
            contact: $ticket->contact,
            isInternalNote: false,
        );

        return response()->json([
            'success' => true,
            'merged' => false,
            'messages' => $this->formatMessages($ticket),
        ]);
    }

    /**
     * Fetch conversation thread messages for the chat widget.
     *
     * Always the session's own ticket: when it was merged, the response says so (merged: true
     * plus a notice) and never shows the primary ticket, which may hold another customer's thread.
     */
    public function messages(string $token): JsonResponse
    {
        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->where('portal_token', $token)->first();

        if ($ticket === null) {
            return response()->json(['error' => 'Chat session not found.'], 404);
        }

        $merged = $ticket->merged_into_ticket_id !== null;

        return response()->json([
            'ticket_number' => $ticket->ticket_number,
            'status' => $ticket->status->value,
            'merged' => $merged,
            'notice' => $merged ? self::MERGED_NOTICE : null,
            'messages' => $this->formatMessages($ticket),
        ]);
    }

    /**
     * Format non-internal messages for public chat display.
     *
     * @return list<array{id: int, sender_type: string, sender_name: string, body: string, is_customer: bool, created_at: string}>
     */
    protected function formatMessages(Ticket $ticket): array
    {
        /** @var list<array{id: int, sender_type: string, sender_name: string, body: string, is_customer: bool, created_at: string}> $list */
        $list = array_values($ticket->messages()
            ->where('is_internal_note', false)
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn (TicketMessage $m): array => [
                'id' => $m->id,
                'sender_type' => $m->sender_type->value,
                'sender_name' => $m->senderName(),
                'body' => $m->body,
                'is_customer' => $m->sender_type === MessageSenderType::Customer,
                'created_at' => $m->created_at?->diffForHumans() ?? 'just now',
            ])
            ->all());

        return $list;
    }

    /**
     * Serve the embeddable chat widget script.
     */
    public function script(): BinaryFileResponse
    {
        return response()->file(__DIR__.'/../../../resources/js/widget.js', [
            'Content-Type' => 'application/javascript; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
