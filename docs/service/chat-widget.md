---
title: Chat widget
description: Embed the support chat widget on any site, and use the chat JSON API that powers it.
---

The package includes `widget.js`, a dependency-free script that adds a chat launcher to any web page. A visitor enters their name, email, and question, which starts a ticket with source `Chat`. Agents answer with the normal ticket actions, and the widget polls for new messages.

## Embedding the widget

The package serves the script itself at `GET /widget.js` in the API route group (route name `odden.service.widget`, so `/api/service/widget.js` with the default prefix), cached for an hour. You can load it straight from there, or publish a copy into your public directory (or asset pipeline) with `php artisan vendor:publish --tag=odden-service-widget`, which writes `public/js/odden-chat-widget.js`.

Add it to the pages where the launcher should appear. Set `window.ODDEN_CHAT_API_URL` to the full base URL of the chat API, including any configured prefix, before the script loads:

```html
<script>
    window.ODDEN_CHAT_API_URL = 'https://crm.example.com/api/service';
</script>
<script src="https://crm.example.com/api/service/widget.js" async></script>
```

Without `ODDEN_CHAT_API_URL`, the widget calls `/api/service` on the page's own origin. Re-publish the file when you update the package if you use a published copy. Copies taken from earlier versions inserted the sender name into the page as HTML, so re-publish the script if yours predates this fix.

The widget:

- adds a launcher button in the bottom-right corner and a chat window, with inline styles;
- shows a start form with name, email, optional company, and message;
- stores the session token in `localStorage` under `odden_support_chat_token`, so a returning visitor sees the same conversation;
- polls for messages every 4 seconds while the window is open;
- renders each message's `sender_name`, `body`, and `created_at` as text (with `textContent`), so HTML in a visitor's name or message is shown as typed, never run;
- loads only once per page (`window.OddenChatWidgetLoaded`).

### Cross-origin use

When the widget runs on a different domain from your Laravel app, the browser sends CORS preflight requests. Laravel's `HandleCors` middleware answers them for the paths in `config/cors.php`, which by default include `api/*` and allow all origins, so the default `api/service` prefix works. If you change `ODDEN_SERVICE_API_PREFIX` to something outside `api/`, or restrict `allowed_origins`, update `config/cors.php` to match.

The chat endpoints are exempt from CSRF verification, so no token is needed. They still run the `web` middleware group by default (see [Configuration reference](configuration.md#routes)), which starts a session for each request.

## What starting a chat does

`POST /chat/start`:

1. Finds or creates a Core `Contact` by email. The lookup ignores case and surrounding whitespace, so `Dana@Example.com` finds the contact `dana@example.com` (and older contacts saved with mixed case). New contacts are stored with the email lowercased and trimmed, the name split at the first space, and `lifecycle_stage` `customer`.
2. If `company` is given, finds or creates a Core `Company` with that exact name (new companies get `lifecycle_stage` `customer`) and associates the contact with it.
3. Creates the ticket with [`CreateTicketAction`](tickets.md#creating-tickets): subject `Live Chat inquiry from {full name}`, source `Chat`, priority `Medium`, the company from step 2 (or the contact's first company), and the message as description, which becomes the first `Customer` message. Like portal and email tickets, it starts as `New`, gets the default SLA policy, is [routed](routing.md#routing-rules) (a rule with `"source": "chat"` matches only chat tickets, and a matching rule moves it to `Open`), and a task is logged on the contact's timeline.
4. Queues `TicketCreatedNotification` to the contact, only if `odden-service.chat.confirmation_email` is `true` (it is `false` by default, see below).
5. Adds a `System` welcome message: "Hi {first name}! Thanks for reaching out to support. A member of our team has received your message and will reply here momentarily." (with a wave emoji after the name).

### Confirmation email

By default a chat ticket doesn't send the confirmation email. `POST /chat/start` is public, exempt from CSRF verification, and never verifies the email address, so sending it would let anyone, from any site, make your app email any address. The visitor first hears from you by email when an agent replies.

To send it anyway, set `ODDEN_SERVICE_CHAT_CONFIRMATION_EMAIL=true` (`odden-service.chat.confirmation_email`). The email is then the visitor's durable record of the conversation: it carries the portal link and a ticket `Message-ID`, so they can come back to it after clearing their browser, or simply reply by email (see [Email to ticket](inbound-email.md#threading-replies)). The subject and contact name in it are escaped, so Markdown or HTML typed into the chat form is shown as typed, not turned into links. Routing and the timeline task happen either way.

The token returned is the ticket's `portal_token`, so the same conversation is also available at the [support portal](customer-portal.md#the-ticket-page) URL.

### Replying from your agent UI

Reply with [`ReplyTicketAction`](tickets.md#replying-and-internal-notes). The reply shows in the widget on its next poll. Because the chat contact has an email address, a public agent reply also emails them `TicketRepliedNotification`. Internal notes never appear in the widget.

## API reference

All three endpoints are in the `api` route group, under `/api/service` by default.

| Method | URI | Route name | Rate limit | CSRF |
| :--- | :--- | :--- | :--- | :--- |
| POST | `/api/service/chat/start` | `odden.service.chat.start` | `odden-public` | Exempt |
| POST | `/api/service/chat/{token}/message` | `odden.service.chat.message` | `odden-public` | Exempt |
| GET | `/api/service/chat/{token}/messages` | `odden.service.chat.messages` | None | n/a |

If you build your own client, send `Accept: application/json` so validation errors come back as `422` JSON instead of a redirect.

### Start a chat

```bash
curl -X POST https://crm.example.com/api/service/chat/start \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"name": "Dana Scully", "email": "dana@example.com", "company": "Acme", "message": "Is there an API rate limit?"}'
```

| Field | Rules |
| :--- | :--- |
| `name` | required, string, max 255 |
| `email` | required, email, max 255 |
| `message` | required, string |
| `company` | optional, string, max 255 |

Response, `201`:

```json
{
    "success": true,
    "token": "3Ekp9wqpTbwiMh55MGZdv1aOUu3QOWG9kulLvRJf",
    "ticket_number": "TICK-2026-7WBPJ",
    "messages": [
        {
            "id": 1,
            "sender_type": "customer",
            "sender_name": "Dana Scully",
            "body": "Is there an API rate limit?",
            "is_customer": true,
            "created_at": "0 seconds ago"
        },
        {
            "id": 2,
            "sender_type": "system",
            "sender_name": "System Automation",
            "body": "Hi Dana! 👋 Thanks for reaching out to support. A member of our team has received your message and will reply here momentarily.",
            "is_customer": false,
            "created_at": "0 seconds ago"
        }
    ]
}
```

Each message has `id`, `sender_type` (`customer`, `agent`, or `system`), `sender_name` (see `TicketMessage::senderName()`), `body`, `is_customer`, and `created_at` as a relative time string such as `"5 minutes ago"`. Internal notes are never included.

### Send a message

```bash
curl -X POST https://crm.example.com/api/service/chat/3Ekp9wqpTbwiMh55MGZdv1aOUu3QOWG9kulLvRJf/message \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"message": "Thanks, that helps."}'
```

`message` is required. The message is added as a `Customer` message through [`ReplyTicketAction`](tickets.md#replying-and-internal-notes), which moves a `New` or `WaitingOnCustomer` ticket to `Open` and reopens a `Resolved` or `Closed` ticket unless `odden-service.reopen_on_customer_reply` is `false`. The response is `{"success": true, "merged": false, "messages": [...]}` with the full public thread. If the chat's ticket was [merged](routing.md#merging-tickets) into another, the message is refused: the response is `409` with `{"success": false, "merged": true, "error": "...", "notice": "...", "messages": [...]}`, where `messages` is the chat's own (now empty) thread and the notice tells the customer the conversation moved and to check their email. An unknown token returns `404` with `{"error": "Chat session not found."}`.

### Fetch messages

```bash
curl https://crm.example.com/api/service/chat/3Ekp9wqpTbwiMh55MGZdv1aOUu3QOWG9kulLvRJf/messages
```

Response:

```json
{
    "ticket_number": "TICK-2026-7WBPJ",
    "status": "open",
    "merged": false,
    "notice": null,
    "messages": []
}
```

`messages` has the same shape as above.

A chat session never follows a merge. The chat widget doesn't verify the visitor's email address, so anyone can start a chat in another customer's name, and the chat ticket is attached to that customer's contact. Once the chat's ticket is merged into another ticket, both endpoints keep returning the chat's own ticket (its `ticket_number`, `status`, and public messages; the merge moved its messages to the primary, so the list is empty), with `merged` set to `true` and `notice` set to "This conversation has moved to another support ticket. Please check your email for updates from our support team and reply there." The bundled widget shows the notice and hides the message box. The primary ticket's thread, number, and status are never exposed through the chat token. See [Replies to merged tickets](routing.md#replies-to-merged-tickets).

An unknown token returns `404` with `{"error": "Chat session not found."}`. This endpoint uses the `odden-poll` limiter (120 requests per minute per IP by default), sized for the widget's polling.
