---
title: Routing, canned responses, and merging
description: Assign new tickets with round-robin routing rules, store reusable replies, and merge duplicate tickets.
---

This page covers three tools for working the ticket queue: routing rules that pick an owner for new tickets, canned responses for common replies, and merging duplicate tickets into one.

## Routing rules

An `Odden\Service\Models\TicketRoutingRule` matches tickets by criteria and assigns them to a pool of users in turn.

| Attribute | Default | Notes |
| :--- | :--- | :--- |
| `name` | | Shown in the contact timeline note. |
| `is_active` | `true` | Inactive rules are skipped. |
| `sort_order` | `0` | Rules are tried in ascending order. |
| `criteria` | `null` | Array of conditions, all of which must match. Empty or `null` matches every ticket. |
| `assigned_user_ids` | | Array of user IDs. Rules with an empty pool are skipped. |
| `last_assigned_index` | `-1` | Round-robin position, updated on each assignment. |

Supported criteria keys:

| Key | Matches when |
| :--- | :--- |
| `priority` | The ticket's priority value equals it, for example `'urgent'`. |
| `source` | The ticket's source value equals it, for example `'email'`. |
| `keyword` | The subject or description contains it (case-insensitive). |
| `has_company` | `true`: the ticket has a company. `false`: it doesn't. |

Other keys are ignored.

```php
use Odden\Service\Models\TicketRoutingRule;

TicketRoutingRule::create([
    'name' => 'Urgent email',
    'sort_order' => 1,
    'criteria' => ['priority' => 'urgent', 'source' => 'email'],
    'assigned_user_ids' => [$alice->id],
]);

TicketRoutingRule::create([
    'name' => 'Billing',
    'sort_order' => 2,
    'criteria' => ['keyword' => 'invoice', 'has_company' => true],
    'assigned_user_ids' => [$bob->id, $carol->id],
]);

TicketRoutingRule::create([
    'name' => 'Everything else',
    'sort_order' => 99,
    'assigned_user_ids' => [$alice->id, $bob->id, $carol->id],
]);
```

### How routing runs

`CreateTicketAction` calls `RouteTicketAction` for every ticket created without an owner, which includes tickets from the [support portal](customer-portal.md), the [email webhook](inbound-email.md), and the [chat widget](chat-widget.md). Use `'source' => 'chat'` to send chat tickets to a dedicated pool. You can route any ticket yourself:

```php
use Odden\Service\Actions\RouteTicketAction;

$result = app(RouteTicketAction::class)->execute($ticket);

if ($result !== null) {
    $result['assigned_user_id']; // int
    $result['rule'];             // the TicketRoutingRule that matched
}
```

The first active rule (by `sort_order`) whose criteria match wins. The action then:

- picks the next user in that rule's pool after `last_assigned_index`, wrapping around, and saves the new index;
- sets the ticket's `owner_id`, and moves the status from `New` to `Open` (other statuses are left alone);
- logs a note on the contact's timeline: "Support Ticket #{number} auto-assigned to {name} via routing rule [{rule}]."

It returns `null` when no rule matches. Routing doesn't consider workload or whether an agent is available; keep the pools up to date.

## Canned responses

`Odden\Service\Models\CannedResponse` stores reusable replies:

| Attribute | Default | Notes |
| :--- | :--- | :--- |
| `title` | | |
| `shortcut` | | Unique, for example `/reset`. |
| `category` | `General` | |
| `content` | | The reply text. |
| `user_id` | `null` | The author, through the `user()` relationship. |
| `is_shared` | `true` | Whether other agents should see it. |

`CannedResponse::availableTo($userId)` limits a query to the shared responses plus the agent's own (the Filament panel uses it), and `$canned->render($ticket, $agent)` fills in the variables before you post the content with `ReplyTicketAction`:

```php
use Odden\Service\Actions\ReplyTicketAction;
use Odden\Service\Models\CannedResponse;

CannedResponse::create([
    'title' => 'Password reset steps',
    'shortcut' => '/reset',
    'category' => 'Accounts',
    'content' => 'You can reset your password from the sign-in page using "Forgot password".',
    'user_id' => $agent->id,
]);

$canned = CannedResponse::where('shortcut', '/reset')->firstOrFail();

app(ReplyTicketAction::class)->execute(
    ticket: $ticket,
    body: $canned->render($ticket, $agent),
    user: $agent,
);
```

Use `CannedResponse::query()->availableTo($agent->id)` instead of `CannedResponse::where(...)` when you list responses for an agent, since a plain query returns other agents' private ones too.

### Variables

`render()` replaces `{{ tag }}` variables (spaces inside the braces are optional):

| Tag | Value |
| --- | --- |
| `{{contact.first_name}}`, `{{contact.last_name}}`, `{{contact.name}}`, `{{contact.email}}` | The ticket's contact |
| `{{company.name}}` | The ticket's company |
| `{{ticket.number}}`, `{{ticket.subject}}`, `{{ticket.status}}` | The ticket (`status` is its label) |
| `{{agent.name}}` | The agent you pass |

Tags that aren't in the table, or whose value is empty (no contact, say), are left as written, so an agent sees what still needs filling in. `content` itself is stored unchanged.

## Merging tickets

When a customer opens the same issue twice, merge the duplicate into the ticket you want to keep:

```php
use Odden\Service\Actions\MergeTicketsAction;

$primary = app(MergeTicketsAction::class)->execute(
    primaryTicket: $primaryTicket,
    secondaryTicket: $duplicateTicket,
    reason: 'Same customer, same issue',
    performedByUserId: $request->user()->id,
);
```

`MergeTicketsAction::execute(Ticket $primaryTicket, Ticket $secondaryTicket, ?string $reason = null, ?int $performedByUserId = null): Ticket` runs in a database transaction and:

1. Moves every message from the secondary ticket to the primary. Messages keep their timestamps, so the primary thread shows both conversations in time order.
2. Adds an internal `System` note to the primary: "Ticket #{number} ('{subject}') was merged into this ticket." plus the reason if given.
3. Sets the secondary ticket's `merged_into_ticket_id` and `merged_at`, and closes it (`Closed`, `closed_at` set).
4. Adds an internal `System` note to the secondary ticket saying it was merged into the primary.

It returns a fresh copy of the primary ticket. Merging a ticket into itself throws an `InvalidArgumentException`. The secondary ticket's SLA fields, CSAT, owner, and contact are not copied.

Use `$ticket->mergedInto` and `$ticket->mergedTickets` to navigate merges.

The secondary ticket keeps its number and portal token, and stays closed.

### Replies to merged tickets

Customer replies that reference a merged ticket by email (its portal link, or the Message-ID of an email sent about it) are posted on the primary ticket instead, as the merge note says. If the primary was itself merged later, they follow the chain to the last ticket (`$ticket->mergeTarget()`). A reply reopens the primary if it is resolved or closed, unless `odden-service.reopen_on_customer_reply` is `false`. Who may reply is still decided by the secondary ticket: an email reply must come from the secondary ticket's contact. See [Email to ticket](inbound-email.md#threading-replies) and [Statuses](tickets.md#statuses).

The portal and the chat widget are stricter, because they hand a ticket's token straight to whoever filled in the form and never verify the email address they typed. Anyone can open a portal ticket or a chat in another customer's name, and the ticket is attached to that customer's contact, so once an agent merges it into the customer's real ticket, its token must not lead there. `$ticket->portalTokenFollowsMerge()` decides: it is `true` only for tickets whose token reached the customer by email alone (source `Email`, `Phone`, or `Api`) and `false` for `WebPortal` and `Chat` tickets.

- The portal page and the chat widget always show the token's own ticket, never the primary's thread, number, or status.
- A portal reply with the token of a merged `Email`, `Phone`, or `Api` ticket is posted on the primary and redirects back with "This ticket was merged into ticket #{number}. Your reply has been posted there and our team will follow up." The primary's portal link isn't shown, because the primary may belong to a different contact.
- A portal reply with the token of a merged `WebPortal` or `Chat` ticket is refused with a `body` validation error asking the customer to reply to the latest email from your team. Nothing is posted.
- A chat message to a merged chat ticket is refused (`409`, `merged: true`), and fetching the chat's messages returns `merged: true` with a notice pointing the customer at their email. See [Chat widget](chat-widget.md#fetch-messages).
