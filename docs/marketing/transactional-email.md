---
title: Transactional email
description: Send a marketing template to one recipient or a batch over HTTP, with merge data, attachments, and signed webhook notifications.
---

The transactional API sends a [template](email-templates.md) on demand, for example an order receipt from your checkout service or a password reset from another app. Each request builds the email and puts it on the queue, then returns; a queue worker delivers it. Run a worker for the marketing mail queue (see [Sending mail](index.md#sending-mail)), or nothing is sent.

Both endpoints are in the `api` route group, require the [API token](index.md#the-api-token), are CSRF exempt, and are limited by `throttle:odden-api`.

| Method | URI | Route name |
| :--- | :--- | :--- |
| `POST` | `/api/marketing/templates/{template}/send` | `odden.marketing.templates.send` |
| `POST` | `/api/marketing/templates/{template}/send-batch` | `odden.marketing.templates.send-batch` |

`{template}` is a template id if it's numeric, otherwise a slug. A template whose slug is all digits can only be reached by its id.

## Sending one email

Given this template:

```php
use Odden\Marketing\Models\MarketingTemplate;

MarketingTemplate::create([
    'name' => 'Order receipt',
    'slug' => 'order-receipt',
    'subject' => 'Order {{order.number}} confirmed',
    'body_html' => '<p>Hi {{contact.first_name}}, your order {{order.number}} total is {{order.total | currency}}.</p>',
]);
```

Send it with:

```bash
curl -X POST https://example.com/api/marketing/templates/order-receipt/send \
  -H "Authorization: Bearer $ODDEN_MARKETING_API_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "to": "sam@example.com",
    "name": "Sam",
    "data": {"order": {"number": "A-1001", "total": "49.5"}},
    "reply_to": "support@acme.test"
  }'
```

The email reads "Hi Sam, your order A-1001 total is $49.50." The response comes back once the email is queued, before it's delivered:

```json
{
    "success": true,
    "message": "Transactional email queued for delivery.",
    "queued": true,
    "template_id": 1,
    "template_slug": "order-receipt",
    "recipient": "sam@example.com",
    "variant": "A",
    "subject": "Order A-1001 confirmed"
}
```

### Request fields

| Field | Rules | Purpose |
| :--- | :--- | :--- |
| `to` | required, email | Recipient address |
| `name` | optional | Recipient display name; also fills `contact.first_name` (see below) |
| `data` | optional object | Merge tag values |
| `context` | optional object | Recipient context for [conditional slots](email-templates.md#conditional-slots) |
| `subject` | optional | Overrides the template subject. Merge tags in it are filled too |
| `from_email`, `from_name` | optional | Sender. Defaults to your `mail.from` address |
| `reply_to` | optional, email | Reply-to address |
| `variant` | optional, `A` or `B` | Which [variant](email-templates.md#variant-b) to send. Defaults to the template's `ab_winner_variant`, then `A` |
| `attachments` | optional array | See [Attachments](#attachments) |
| `preview_text` | optional, max 255 | Overrides the inbox preview text of a slot-built email. Defaults to the template's `preview_text` (`preview_text_variant_b` for variant B) |
| `webhook_url`, `webhook_secret` | optional | See [Webhook notifications](#webhook-notifications) |

### How the email is built

1. The variant's slots are compiled with the template's `theme`, the request's `context`, and the subject. Without slots, the variant's stored HTML is used. The template's preview text (or the request's `preview_text`) is passed to the compiler, so slot-built emails carry their preheader.
2. The HTML, its plain-text version, and the subject are run through the mail builder [merge tag interpolator](email-templates.md#in-the-mail-builder) with `data`. Filters and conditionals work; tags without a value are left as written.
3. The message is an `Odden\Marketing\Mail\TransactionalTemplateMailable` (a queued `Odden\MailBuilder\Mail\TemplateMailable`), queued through the `odden-marketing.mail.mailer` mailer on the `odden-marketing.mail.connection` and `odden-marketing.mail.queue` queue. The HTML is final when it's queued, so later template edits don't change it.

About `name` and `data`:

- `data` can be nested (`{"order": {"number": "A-1001"}}`) or use flat dotted keys (`{"order.number": "A-1001"}`).
- When you send `name`, it's also stored as the flat key `contact.first_name`, unless `data` already has that flat key. Flat keys take precedence over nested ones, so to use a different first name, send `"contact.first_name"` as a flat key in `data`.
- If `data` contains `unsubscribe_url`, the email gets `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` headers pointing at it.

The API doesn't create campaign recipients, add open or click tracking, or check [subscriptions](subscriptions-and-compliance.md#what-the-transactional-api-checks) and the suppression list. Template translations aren't used.

### Attachments

Attachments are sent inline, base64 encoded. Each item in `attachments` needs a `name` and `data`:

| Field | Rules |
| :--- | :--- |
| `name` | required; the file name shown to the recipient |
| `data` | required; the file contents, base64 encoded |
| `mime` | optional; MIME type, up to 100 characters |
| `is_base64` | optional; if you send it, it must be `true` |

```json
{
    "to": "sam@example.com",
    "attachments": [
        {"name": "invoice.pdf", "data": "JVBERi0xLjQK...", "mime": "application/pdf"}
    ]
}
```

The API can't attach files from your server or fetch them from a URL. A request with an `attachments.*.path` field gets a `422`, as does `data` that isn't valid base64.

### Errors

| Status | When |
| :--- | :--- |
| `401` | Missing or wrong API token |
| `403` | `ODDEN_MARKETING_API_TOKEN` isn't set |
| `404` | No template matches: `{"error": "Marketing template not found."}` |
| `422` | Validation failed. Send `Accept: application/json` to get the errors as JSON |
| `429` | The `odden-api` rate limit was hit |

A failure to queue the email (for example, the queue connection is down) isn't caught and returns a `500`. A delivery failure happens later, in the queue worker: the API has already returned `200`, and the job fails and is retried like any other queued job.

## Sending a batch

`send-batch` queues the same template to up to 1,000 recipients, each with their own merge data:

```bash
curl -X POST https://example.com/api/marketing/templates/order-receipt/send-batch \
  -H "X-Odden-Token: $ODDEN_MARKETING_API_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "recipients": [
      {"to": "ana@gmail.com", "name": "Ana", "data": {"order": {"number": "A-1"}}},
      {"to": "ben@acme.test", "data": {"order": {"number": "A-2"}}}
    ],
    "throttle_domains": true
  }'
```

| Field | Rules |
| :--- | :--- |
| `recipients` | required, 1–1000 items |
| `recipients.*.to` | required, email |
| `recipients.*.name` | optional |
| `recipients.*.data` | optional object |
| `subject`, `from_email`, `from_name`, `reply_to`, `variant` | as for a single send |
| `preview_text`, `webhook_url`, `webhook_secret` | as for a single send |
| `throttle_domains` | optional boolean; adds a `throttle_plan` to the response |

The response lists who was queued for:

```json
{
    "success": true,
    "message": "Batch transactional emails queued for delivery.",
    "queued": true,
    "template_id": 1,
    "template_slug": "order-receipt",
    "dispatched_count": 2,
    "recipients": ["ana@gmail.com", "ben@acme.test"],
    "throttle_plan": {
        "total_recipients": 2,
        "domain_distribution": {"gmail.com": 1, "acme.test": 1},
        "waves": [
            {"wave_index": 0, "offset_seconds": 0, "count": 2, "recipients": [{"to": "ana@gmail.com", "name": "Ana", "data": {"order": {"number": "A-1"}}}, {"to": "ben@acme.test", "data": {"order": {"number": "A-2"}}}]}
        ],
        "estimated_dispatch_duration_seconds": 0
    }
}
```

Differences from a single send:

- Each email is queued as its own job. If queueing one throws, the rest aren't queued and the response is a `500`; the ones already queued are still delivered.
- `throttle_plan` is only a suggestion from [`DomainThrottler`](deliverability.md#throttling-by-domain). The emails are already queued when you receive it.
- The HTML is compiled once without a `context`, so conditional slots aren't filtered per recipient.
- `attachments` isn't supported.

## Webhook notifications

Pass `webhook_url` and the API posts a notification there once the email is queued (not when it's delivered; the event names are unchanged), signed with `webhook_secret` (or the `odden-marketing.webhooks.secret` config key):

| Endpoint | Event | `data` |
| :--- | :--- | :--- |
| `send` | `template.email.sent` | `template_id`, `template_slug`, `recipient`, `variant`, `subject` |
| `send-batch` | `template.email.batch_sent` | `template_id`, `template_slug`, `dispatched_count`, `recipients`, `variant` |

```json
{
    "id": "9b2f6c4e-…",
    "event": "template.email.sent",
    "timestamp": 1767225600,
    "data": {"template_id": 1, "template_slug": "order-receipt", "recipient": "sam@example.com", "variant": "A", "subject": "Order A-1001 confirmed"}
}
```

The request has these headers: `X-Odden-Event`, `X-Odden-Delivery` (the `id`), `X-Odden-Timestamp`, and `X-Odden-Signature`. It's sent synchronously with a 5-second timeout. A failure is logged as a warning and doesn't change the email's success. The API response says what happened: `webhook_dispatched` is `true` when the receiver answered 2xx, and `false` with a `webhook_warning` when the webhook was skipped (no signing secret) or failed. Without a `webhook_url`, neither key is returned.

Without `webhook_url`, the URL comes from the `odden-marketing.webhooks.outbound_url` config key, if set. The signing secret is `webhook_secret`, then `odden-marketing.webhooks.secret`. If neither is set, the webhook isn't sent and a warning is logged; there's no default secret.

```env
ODDEN_MARKETING_WEBHOOK_URL=https://example.com/hooks/odden
ODDEN_MARKETING_WEBHOOK_SECRET=a-long-random-string
```

### Verifying the signature

`X-Odden-Signature` has the form `t={timestamp},v1={hex}`, where the hex is an HMAC-SHA256 of `{timestamp}.{body}` keyed with the secret, and `{body}` is the raw request body. Verify against the body exactly as received, before decoding it:

```php
use Odden\Marketing\Services\MarketingWebhookDispatcher;
use Illuminate\Http\Request;

Route::post('/hooks/odden', function (Request $request) {
    abort_unless(MarketingWebhookDispatcher::verifySignature(
        payload: $request->getContent(),
        headerSignature: (string) $request->header('X-Odden-Signature'),
        secret: config('services.odden.webhook_secret'),
    ), 401);

    // handle $request->input('event')
});
```

`verifySignature(string $payload, string $headerSignature, string $secret, int $tolerance = 300): bool` also rejects timestamps more than `$tolerance` seconds old or in the future.

## Sending from PHP

There's no action class for transactional sends, but the API is a thin layer over `TransactionalTemplateMailable`, so you can do the same in your own code:

```php
use Odden\Marketing\Mail\TransactionalTemplateMailable;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Support\MarketingMailer;

$template = MarketingTemplate::query()->where('slug', 'order-receipt')->firstOrFail();

MarketingMailer::queue(new TransactionalTemplateMailable(
    template: $template->getVariantHtml('A'),
    data: ['contact' => ['first_name' => 'Sam'], 'order' => ['number' => 'A-1001', 'total' => '49.5']],
    subjectLine: $template->getVariantSubject('A'),
    replyToEmail: 'support@acme.test',
), 'sam@example.com', 'Sam');
```

`TransactionalTemplateMailable` implements `ShouldQueue` and picks up the `odden-marketing.mail` connection and queue when it's constructed. `MarketingMailer::queue()` sends it through the `odden-marketing.mail.mailer` mailer; with plain `Mail::to(...)->queue(...)` it goes through your default mailer instead.
