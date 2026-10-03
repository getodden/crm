---
title: Email to ticket
description: Turn inbound emails into tickets and thread customer replies with the token-protected inbound email webhook.
---

The inbound email webhook turns emails sent to your support address into tickets. Configure your email provider's inbound parsing (inbound routes or parse webhooks) to post each message to this endpoint. New conversations become tickets. A reply is added to an existing ticket's thread only when it carries that ticket's secret portal token and comes from the ticket's contact.

## Endpoint

| | |
| :--- | :--- |
| Method and URI | `POST /api/service/inbound-email` |
| Route name | `odden.service.inbound-email` |
| Authentication | Service API token (required) |
| Rate limit | `odden-api` (600 requests per minute per IP by default) |
| CSRF | Exempt |

The `/api/service` prefix comes from `odden-service.routes.api.prefix`. See [Configuration reference](configuration.md#routes).

## API token

The endpoint is protected by the service API token:

```env
ODDEN_SERVICE_API_TOKEN=a-long-random-string
```

Generate a value with `php -r 'echo bin2hex(random_bytes(32));'`. It is read from `odden-service.api.token`.

Until a token is set, the endpoint returns `403` and creates nothing. A missing or wrong token returns `401`. Send the token as `Authorization: Bearer <token>`, an `X-Odden-Token` header, or a `?token=` query parameter for providers that only let you enter a URL. See [API tokens](../configuration.md#api-tokens) for details shared by all Odden modules.

## Request

The webhook accepts JSON or form-encoded bodies. For the sender, subject, and body it reads the first field that is present:

| Value | Fields read, in order |
| :--- | :--- |
| Sender | `from`, `sender`, `From` |
| Subject | `subject`, `Subject` (defaults to `No Subject`) |
| Body | `body`, `text`, `stripped-text`, `html`, `body_html` |

For [threading](#threading-replies) it also reads these optional fields. Unlike the fields above, every one that is present is used:

| Value | Fields read |
| :--- | :--- |
| Reply headers | `in_reply_to`, `In-Reply-To`, `references`, `References` (body fields), and the `In-Reply-To` and `References` HTTP headers of the webhook request |
| Raw headers | `headers`, `Headers`: only their `In-Reply-To` and `References` entries, see below |
| Sender verdict | `sender_authenticated`, `dmarc`, only when [authentication is required](#requiring-sender-authentication) |

`headers` and `Headers` may hold any of these shapes. Header names are matched case-insensitively and other headers are ignored:

- A raw header block as text, as SendGrid's Inbound Parse sends in `headers`: `"In-Reply-To: <...>\r\nReferences: <...>"`. Folded lines are unfolded.
- An object of names to values: `{"In-Reply-To": "<...>"}`. A value may be a list of strings.
- A list of `{"Name": ..., "Value": ...}` objects (or lowercase `name` and `value`), as Postmark sends in `Headers`.
- A list of `[name, value]` pairs.

The sender may be a bare address (`dana@example.com`) or a name and address (`Dana Scully <dana@example.com>`). With a bare address, the part before the `@` is used as the name.

Check these names against what your provider sends. Some providers use other field names (Postmark's JSON, for example, sends the body as `TextBody` and `HtmlBody`, and Mailgun sends its headers as a JSON-encoded string in `message-headers`, neither of which the webhook reads), so you may need a small route in your app that maps the fields and forwards the request. Attachments are ignored. An HTML-only body is stored as the HTML source.

```bash
curl -X POST https://crm.example.com/api/service/inbound-email \
  -H "Authorization: Bearer $ODDEN_SERVICE_API_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"from": "Dana Scully <dana@example.com>", "subject": "Cannot log in", "text": "The sign-in page says my account is locked."}'
```

## Responses

A new ticket returns `201`:

```json
{
    "status": "created",
    "ticket_number": "TICK-2026-7WBPJ",
    "portal_url": "https://crm.example.com/support/tickets/3Ekp9wqpTbwiMh55MGZdv1aOUu3QOWG9kulLvRJf"
}
```

A reply added to an existing ticket returns `200`:

```json
{
    "status": "appended",
    "ticket_number": "TICK-2026-7WBPJ",
    "message_id": 42
}
```

A request without a sender or body returns `422`:

```json
{
    "error": "Missing required email fields (from, body/text)."
}
```

## New tickets

When the email isn't a reply that passes every [threading](#threading-replies) check:

1. The sender is matched to a Core `Contact` by email address, ignoring case and surrounding whitespace, so `Dana@Example.com` finds the contact `dana@example.com` (and older contacts saved with mixed case). If none exists, a contact is created with the email lowercased and trimmed and the parsed first and last name (first name `Customer` if no name could be parsed).
2. A ticket is created through [`CreateTicketAction`](tickets.md#creating-tickets) with the subject, the body as description and first message, priority `Medium`, and source `Email`.

Because it uses `CreateTicketAction`, the ticket is routed, a task is logged on the contact's timeline, and `TicketCreatedNotification` is queued for the customer. A failed check never returns an error; the email simply becomes a new ticket for its sender.

## Threading replies

A ticket number such as `TICK-2026-7WBPJ` is short and guessable, and the `From` address of an email can be forged, so neither is enough to post on a ticket. The webhook adds an email to an existing ticket only when all of these hold:

1. The email carries the ticket's `portal_token`, the random 40-character secret behind its [customer portal](customer-portal.md) link, in one of the places described below.
2. The sender is the ticket's contact: the sender's email address equals the contact's email address, compared case-insensitively after trimming whitespace. A ticket with no contact never matches.
3. If `odden-service.inbound_email.require_authenticated_sender` is `true`, the request says the sender passed authentication. See [Requiring sender authentication](#requiring-sender-authentication).

Ticket numbers in the subject, body, or headers are ignored for threading. The `[#TICK-2026-7WBPJ]` in notification subjects is there for people to read.

When a ticket is found, the body is added as a `Customer` message from that ticket's contact through [`ReplyTicketAction`](tickets.md#replying-and-internal-notes):

- If the ticket was [merged](routing.md#merging-tickets) into another, the message is posted on the primary ticket, following the merge chain to its end. The token and sender checks above are always made against the ticket the email referenced, not the primary, so a merge never lets the primary's contact use the merged ticket's token, or the reverse. The response's `ticket_number` is the ticket the message landed on.
- A customer message moves the ticket to `WaitingOnAgent`, and reopens a `Resolved` or `Closed` ticket (status `WaitingOnAgent`, `resolved_at` and `closed_at` cleared) while `odden-service.reopen_on_customer_reply` is `true`, the default. With it `false`, the message is added and the status is left alone. The merged ticket itself stays closed; it's the primary that reopens.

### Where the token is found

The webhook collects portal tokens from, in order:

1. The [reply headers](#request): any Message-ID of the form `ticket.{portal_token}.{unique}@{host}`, with or without angle brackets, in `In-Reply-To` or `References`. The host isn't checked.
2. The subject, then the body: a 40-character token directly after `support/tickets/` (the portal link in ticket emails, `/support/tickets/{token}`), `portal/`, or `token=`. HTML bodies match too, because the link is in the `href`.

Each token is looked up in turn, and the first ticket whose contact is the sender is used. A token for someone else's ticket is skipped, so a reply can't be moved onto another customer's ticket by quoting their link.

### Message-IDs on ticket emails

`TicketCreatedNotification`, `TicketRepliedNotification`, and `TicketResolvedCsatNotification`, the emails sent to the ticket's contact, set their own `Message-ID` header:

```text
<ticket.{portal_token}.{16 random hex characters}@{host}>
```

`{host}` is the host of `app.url` (`localhost` if it has none). Mail clients copy this ID into the `In-Reply-To` and `References` headers of a reply, so a customer who replies to any of these emails is threaded even if they delete the quoted text and the subject. The token is already in the portal link in each of these emails, so the header exposes nothing new. `SlaBreachAlertNotification`, which goes to the ticket owner, doesn't set one.

The ID is set through `MailMessage::withSymfonyMessage()` by the `Odden\Service\Notifications\Concerns\SetsTicketMessageId` trait. Some sending services replace the `Message-ID` with their own (Amazon SES does, for example). Replies to those emails can't be threaded by header, but still are by the portal link when the customer's client quotes the original email.

Emails sent before this scheme was added have no ticket Message-ID, so replies to them are threaded only by a portal link in the quoted text.

### Replies from other addresses

Only the ticket's contact can add to a ticket by email. If a customer CCs a colleague and the colleague replies, the reply carries the right token but comes from a different address, so it opens a new ticket for the colleague, with them as its contact. The same happens when the customer replies from a different address than the one on their contact record. Merge the tickets if they belong together; see [Merging tickets](routing.md#merging-tickets).

### Requiring sender authentication

The sender check compares the `From` address your provider reports, which a forger can set. Holding the portal token is what makes a forged reply hard, but you can also require your provider's verdict on the sender:

```env
ODDEN_SERVICE_INBOUND_REQUIRE_AUTH=true
```

This sets `odden-service.inbound_email.require_authenticated_sender` (default `false`). While it's `true`, an email is only threaded when the request has either:

- `sender_authenticated` set to `true`, `1`, `"true"`, `"yes"`, `"on"`, or `"pass"`, or
- `dmarc` set to `"pass"`.

Both are case-insensitive. Anything else, including a missing field, counts as not authenticated, and the email opens a new ticket for its sender. While the setting is `false`, both fields are ignored.

DMARC is used because it is the check that ties the `From` domain to SPF or DKIM; an SPF or DKIM pass on its own can be for any domain the forger controls. Most providers don't send a field with exactly this name, so map their result in the route that forwards to the webhook. Set `sender_authenticated` from the SPF, DKIM, and DMARC results your provider includes (SendGrid's Inbound Parse, for example, sends `SPF` and `dkim` fields), according to your own policy.

```bash
curl -X POST https://crm.example.com/api/service/inbound-email \
  -H "Authorization: Bearer $ODDEN_SERVICE_API_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"from": "dana@example.com", "subject": "Re: Cannot log in", "text": "Still locked out.", "in_reply_to": "<ticket.3Ekp9wqpTbwiMh55MGZdv1aOUu3QOWG9kulLvRJf.9f86d081884c7d65@crm.example.com>", "dmarc": "pass"}'
```

Keep treating inbound messages as customer input.
