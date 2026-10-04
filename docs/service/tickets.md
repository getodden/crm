---
title: Tickets and conversations
description: The Ticket and TicketMessage models, ticket statuses, and how to create, reply to, resolve, close, and reopen tickets.
---

A ticket is a support request from a customer. Its conversation is a list of `TicketMessage` records: customer messages, agent replies, system messages, and internal notes. This page covers the models and the actions that change them.

## The ticket model

`Odden\Service\Models\Ticket` stores:

| Attribute | Type | Notes |
| :--- | :--- | :--- |
| `ticket_number` | string | Generated on create, see [Ticket numbers](#ticket-numbers-and-portal-tokens). Unique. |
| `portal_token` | string | 40-character random token for the customer's ticket page and chat session. Unique. |
| `subject`, `description` | string | `description` is optional. |
| `status` | `TicketStatus` | Defaults to `new`. |
| `priority` | `TicketPriority` | Defaults to `medium`. |
| `source` | `TicketSource` | Defaults to `web_portal`. |
| `contact_id`, `company_id` | int, nullable | Core `Contact` and `Company`. |
| `owner_id` | int, nullable | The assigned user. |
| `sla_policy_id` | int, nullable | See [SLA policies](sla-policies.md). |
| `team_id` | int, nullable | Stored and indexed. `Ticket::forTeam($teamId)` scopes a query to a team; nothing else in the package filters by it. |
| `first_response_due_at`, `resolution_due_at` | datetime | SLA deadlines, set on create. |
| `first_responded_at`, `resolved_at`, `closed_at` | datetime | Lifecycle timestamps. |
| `is_sla_response_breached`, `is_sla_resolution_breached` | bool | SLA breach flags. |
| `merged_into_ticket_id`, `merged_at` | | Set when the ticket is merged into another, see [Merging](routing.md#merging-tickets). |
| `csat_rating`, `csat_comment` | int (1–5), text | Customer satisfaction feedback. |
| `properties` | array | Custom data through Core's `HasCustomProperties`. |

Relationships: `contact()`, `company()`, `owner()`, `slaPolicy()`, `mergedInto()`, `mergedTickets()`, and `messages()`. `messages()` is always ordered by `created_at` ascending.

The service provider also adds `tickets()` to Core's `Contact` and `Company` models:

```php
$contact->tickets()->where('status', 'open')->count();
$company->tickets;
```

### Ticket numbers and portal tokens

When a ticket is created without a `ticket_number`, one is generated as `{prefix}-{year}-{5 random uppercase letters or digits}`, for example `TICK-2026-7WBPJ`. The prefix comes from `odden-service.defaults.prefix` (default `TICK`). A random 40-character `portal_token` is generated the same way.

`getPortalUrl()` returns the customer's ticket page (`route('odden.support.show', $token)`), and `getCsatUrl()` returns the satisfaction survey (`route('odden.support.rate', $token)`). See [Support portal and CSAT](customer-portal.md).

### SLA deadlines on create

When a ticket is created without an `sla_policy_id`, the active policy with `is_default = true` is attached if one exists. If the ticket then has a policy and no `first_response_due_at`, both deadlines are calculated from the policy's targets for the ticket's priority. Changing the priority later recalculates unmet deadlines (see [SLA policies](sla-policies.md)); changing the policy does not, so call `recalculateSlaDueDates()` yourself. See [SLA policies](sla-policies.md).

## Statuses

| Status | Set when |
| :--- | :--- |
| `New` | The ticket is created. |
| `Open` | A routing rule assigns a `New` ticket, or you call `reopen()`. |
| `WaitingOnCustomer` | The first public agent reply is posted (unless the ticket is `Resolved` or `Closed`). |
| `WaitingOnAgent` | A customer posts a public message on the ticket (portal, email or chat), including to a `Resolved` or `Closed` ticket while `reopen_on_customer_reply` is on. It means the ball is with your agents. |
| `Resolved` | You call `resolve()` or `ResolveTicketAction`. |
| `Closed` | You call `close()`, the ticket is merged into another, or `service:run-automations` closes it. |

These transitions happen inside `Ticket::addMessage()`, which every action and public endpoint uses to post messages:

- A public `Agent` message on a ticket with no `first_responded_at` sets `first_responded_at` to now, sets `is_sla_response_breached` to whether the response was late, and moves the status to `WaitingOnCustomer` unless the ticket is resolved or closed. Later agent replies don't change the status.
- A public `Customer` message moves the ticket to `WaitingOnAgent`, whatever its open status was. On a `Resolved` or `Closed` ticket it reopens the ticket (status `WaitingOnAgent`, `resolved_at` and `closed_at` cleared) when `odden-service.reopen_on_customer_reply` is `true`, the default (`ODDEN_SERVICE_REOPEN_ON_CUSTOMER_REPLY`). Set it to `false` to keep resolved and closed tickets as they are; the message is still added. A ticket that was [merged](routing.md#merging-tickets) into another is never reopened. Customer replies through `ReplyTicketAction` go to its primary instead, see [Replying](#replying-and-internal-notes).
- Internal notes and `System` messages never change the status.

These status updates are saved quietly (without model events).

## Creating tickets

Use `CreateTicketAction` for tickets from your own code, such as phone calls logged by an agent or an integration:

```php
use Odden\Core\Models\Contact;
use Odden\Service\Actions\CreateTicketAction;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;

$contact = Contact::where('email', 'dana@example.com')->firstOrFail();

$ticket = app(CreateTicketAction::class)->execute(
    subject: 'CSV export times out',
    description: 'The export runs for a minute and then fails.',
    priority: TicketPriority::High,
    source: TicketSource::Api,
    contact: $contact,
    properties: ['plan' => 'enterprise'],
);
```

The full signature:

```php
public function execute(
    string $subject,
    ?string $description = null,
    TicketPriority $priority = TicketPriority::Medium,
    TicketSource $source = TicketSource::WebPortal,
    ?Contact $contact = null,
    ?Company $company = null,
    ?Model $owner = null,
    ?SlaPolicy $slaPolicy = null,
    array $properties = [],
    bool $notifyContact = true
): Ticket
```

The action does more than insert a row:

1. If no `$company` is given and the contact is associated with a company, the contact's first company is used.
2. The ticket is created with status `New`. SLA deadlines are set as described above.
3. If `$description` is not empty, it is also added as the first `Customer` message in the thread.
4. If no `$owner` is given, [`RouteTicketAction`](routing.md#routing-rules) runs. A matching rule assigns an owner and moves the ticket to `Open`.
5. If there is a contact, a pending task titled `Support Ticket #{number}: {subject}` is logged on the contact's timeline, due at the first response deadline.
6. If the contact has an email address and `$notifyContact` is `true`, `TicketCreatedNotification` is queued for them. See [Notifications](#notifications).

`Ticket::create()` also works, and still generates the number, token, and SLA deadlines, but skips steps 1 and 3 to 6.

## Replying and internal notes

`ReplyTicketAction` posts a message to the thread:

```php
use Odden\Service\Actions\ReplyTicketAction;

$reply = app(ReplyTicketAction::class);

$reply->execute(
    ticket: $ticket,
    body: 'Thanks, Dana. We can reproduce this and are working on a fix.',
    user: $agent,
);

$reply->execute(
    ticket: $ticket,
    body: 'Slow query in the export job, see the linked issue.',
    user: $agent,
    isInternalNote: true,
);
```

The full signature:

```php
public function execute(
    Ticket $ticket,
    string $body,
    MessageSenderType $senderType = MessageSenderType::Agent,
    ?Model $user = null,
    ?Contact $contact = null,
    bool $isInternalNote = false,
    ?array $attachments = null
): TicketMessage
```

For a public `Agent` reply on a ticket with a contact, the action also logs a note on the contact's timeline (the first 150 characters of the reply) and, if the contact has an email address, sends `TicketRepliedNotification`. Internal notes and customer messages send nothing.

A public `Customer` message on a ticket that was [merged](routing.md#merging-tickets) into another is posted on the primary ticket instead, following the merge chain to its end (`$ticket->mergeTarget()`), as the merge note promises. If that primary is resolved or closed, the reply reopens it (see [Statuses](#statuses)). The returned message's `ticket_id` tells you where it landed. Agent messages and internal notes are always posted on the ticket you pass. Decide who may reply (for example, the sender check in the [email webhook](inbound-email.md#threading-replies)) against the ticket the customer referenced before calling the action. Don't pass a merged ticket on behalf of someone whose identity you haven't verified: the portal and chat widget refuse replies to merged `WebPortal` and `Chat` tickets instead (`$ticket->portalTokenFollowsMerge()`), see [Replies to merged tickets](routing.md#replies-to-merged-tickets).

`$attachments` is stored as a JSON array on the message as given. The package doesn't upload or serve files; store them yourself and save whatever references you need.

To record a customer message from your own code, pass `senderType: MessageSenderType::Customer` and `contact:`.

### Drafting a reply

In the panel, **Draft a reply** above the reply box fills it with a starting point for the agent to edit. `DraftTicketReplyAction::execute(Ticket $ticket, ?Model $agent = null)` greets the contact by first name, uses the canned response that best matches the subject and the customer's latest message (only ones the agent may use, with its `{{ tags }}` filled in), links up to two matching published help articles, and signs off with the agent's name. With no match it writes a general acknowledgement. It returns `body`, `sources` (what it drew from) and a `rationale`. It never posts anything and calls no outside service.

The panel asks the container for `Odden\Service\Contracts\DraftsTicketReply`, so an add-on can [bind a different drafter](../filament/customizing.md#swapping-a-built-in-behaviour) that returns the same shape.

### Ticket::addMessage()

Both the action and the public endpoints call `Ticket::addMessage()`, which you can also call directly when you don't want the timeline note or email:

```php
public function addMessage(
    string $body,
    MessageSenderType $senderType = MessageSenderType::Agent,
    ?int $userId = null,
    ?int $contactId = null,
    bool $isInternalNote = false,
    ?array $attachments = null
): TicketMessage
```

### Messages

`TicketMessage` has `ticket_id`, `sender_type` (`MessageSenderType`), `user_id`, `contact_id`, `body`, `is_internal_note`, and `attachments`, with `ticket()`, `user()`, and `contact()` relationships. `senderName()` returns the user's `name`, else the contact's full name, else the sender type label (for example "System Automation").

Message bodies are plain text. The bundled portal pages escape them and convert line breaks.

## Resolving, closing, and reopening

```php
use Odden\Service\Actions\ResolveTicketAction;

app(ResolveTicketAction::class)->execute(
    $ticket,
    resolutionNote: 'Fixed in release 4.2.1.',
);
```

`ResolveTicketAction::execute(Ticket $ticket, ?string $resolutionNote = null, ?int $csatRating = null, ?string $csatComment = null): Ticket`:

- Calls `$ticket->resolve($resolutionNote)`. A non-empty note is posted as a public `Agent` message attributed to the authenticated user (if any). The ticket moves to `Resolved`, `resolved_at` is set, and `is_sla_resolution_breached` is set to whether it was resolved after `resolution_due_at`.
- If `$csatRating` is given, saves it with `$csatComment`. The value is not validated here.
- If there is a contact, logs a note on their timeline, and if they have an email address, sends `TicketResolvedCsatNotification`, which links to the CSAT survey.

The model methods on their own send no email:

| Method | Effect |
| :--- | :--- |
| `resolve(?string $resolutionNote = null)` | As above, without the timeline note or email. |
| `close()` | Status `Closed`, `closed_at` set to now. |
| `reopen()` | Status `Open`, `resolved_at` and `closed_at` cleared. |
| `isFirstResponseBreached()` | `true` if flagged, or if there is no response yet and the deadline has passed. |
| `isResolutionBreached()` | `true` if flagged, or if the ticket is unresolved and the deadline has passed. |

## Notifications

The package sends these notifications on the `mail` channel. All four implement `ShouldQueue`, so they are pushed to the queue and sent by a queue worker, and a slow mail provider doesn't slow down the request or command that triggered them. They use the connection and queue in `odden-service.notifications.connection` and `odden-service.notifications.queue` (`ODDEN_SERVICE_NOTIFICATIONS_CONNECTION`, `ODDEN_SERVICE_NOTIFICATIONS_QUEUE`); both default to `null`, which means your default queue connection and its default queue. Run a worker that listens on that queue, for example `php artisan queue:work --queue=support-mail,default`. With the `sync` connection they are sent immediately, as before. Customer notifications go to the Core `Contact`, which uses Laravel's `Notifiable` trait and its `email` attribute.

The ticket (and message) is serialized by ID and reloaded when the job runs, so the email reflects the ticket at sending time. The `Message-ID` is generated when the email is built in the worker, so threading works the same whether the notification is queued or sent synchronously.

| Notification | Sent to | Sent by | Main link |
| :--- | :--- | :--- | :--- |
| `TicketCreatedNotification` | Contact | `CreateTicketAction` | `getPortalUrl()` |
| `TicketRepliedNotification` | Contact | `ReplyTicketAction` (public agent replies) | `getPortalUrl()` |
| `TicketResolvedCsatNotification` | Contact | `ResolveTicketAction` | `getCsatUrl()` |
| `SlaBreachAlertNotification` | Ticket owner | `CheckSlaBreachesAction` | `odden-service.admin_ticket_url` (default `/admin/tickets/{id}/edit`) |

Customer email subjects start with `[#{ticket_number}]` for the customer's reference. The three customer emails also set a `Message-ID` that contains the ticket's portal token, `<ticket.{portal_token}.{unique}@{host}>`, which the [email webhook](inbound-email.md#threading-replies) uses, along with the portal link, to thread replies from the ticket's contact back into the ticket. The ticket number alone doesn't thread a reply.

Tickets created by the [chat widget](chat-widget.md) go through `CreateTicketAction` but only send the confirmation email when `odden-service.chat.confirmation_email` is `true` (it is `false` by default).

Ticket subjects, contact names, and agent names in these emails are escaped for Markdown (`Odden\Service\Support\MailMarkdown::escape()`), and HTML is escaped by the mail template, so a subject like `[Reset](https://evil.example)` is shown as typed rather than as a link. Use `MailMarkdown::escape()` for customer-supplied values in your own `MailMessage` lines too.

## Automatic closing

`service:run-automations` runs `RunServiceAutomationsAction`, which:

1. Closes tickets in `WaitingOnCustomer` whose `updated_at` is 7 or more days ago. It first posts a public message, "Ticket automatically closed after 7 days without customer response.", with sender type `Agent` and no user. No email is sent.
2. Closes `Resolved` tickets whose `resolved_at` is 48 hours or more ago.

Both periods are fixed in code. The command prints a table of how many tickets each step closed. The package doesn't schedule it; see [Installation](../installation.md#schedule-the-commands).

```bash
php artisan service:run-automations
```
