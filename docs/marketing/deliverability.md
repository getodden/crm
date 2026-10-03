---
title: Deliverability
description: Keep bounced and complaining addresses off your lists with the suppression list and ESP webhooks, lint campaigns before sending, check sender domains, and sunset unengaged subscribers.
---

Mailbox providers judge your sender reputation by bounces, complaints, and engagement. This page covers the tools the package gives you to protect it: a global suppression list fed by your email provider's webhooks, content linting, a DNS check for your sending domain, and a sunset policy for subscribers who stopped opening.

## The suppression list

`Odden\Marketing\Models\EmailSuppression` is a do-not-send list keyed by email address. [Campaign dispatch](campaigns.md#who-is-skipped) skips every address on it, through `MarketingSubscription::isSuppressed()`.

```php
use Odden\Marketing\Models\EmailSuppression;

EmailSuppression::suppress('blocked@example.com', 'manual_blocklist', 'manual', ['note' => 'Requested by legal']);

EmailSuppression::isSuppressed('Blocked@Example.com'); // true

EmailSuppression::remove('blocked@example.com');       // true if a row was deleted
```

`suppress(string $email, string $reason = 'hard_bounce', ?string $source = null, ?array $metadata = null): EmailSuppression` lowercases the address and creates the row only if it doesn't exist; an existing row keeps its original reason.

The package writes these reasons: `hard_bounce`, `spam_complaint`, and `unsubscribe`, with a source of `esp_webhook:{provider}`. Use any other string, such as `manual_blocklist`, for your own entries.

The [transactional API](transactional-email.md) doesn't check this list.

## ESP webhooks

Point your email provider's bounce and complaint webhooks at the package, and it suppresses those addresses automatically. There are two endpoints, both requiring the [API token](index.md#the-api-token). Most providers only let you set a URL, so pass the token as `?token=`:

```text
https://example.com/marketing/webhooks/esp/postmark?token=YOUR_TOKEN
https://example.com/api/marketing/webhooks/deliverability?token=YOUR_TOKEN
```

| Endpoint | Route name | Provider |
| :--- | :--- | :--- |
| `POST /marketing/webhooks/esp/{provider}` (`web` group) | `odden.marketing.webhooks.esp` | From the URL |
| `POST /api/marketing/webhooks/deliverability` (`api` group) | `odden.marketing.webhooks.deliverability` | From a `provider` field in the body, default `generic` |

Both are CSRF exempt and limited by `throttle:odden-api`. The API token is the only check, with one exception: Mailgun webhooks can be authenticated by [Mailgun's own signature](#authenticating-mailgun-webhooks) instead.

The body can be a single event object or a JSON array of events:

```bash
curl -X POST "https://example.com/marketing/webhooks/esp/sendgrid" \
  -H "X-Odden-Token: $ODDEN_MARKETING_API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '[{"email": "bounce@example.com", "event": "bounce", "status": "5.1.1", "reason": "User unknown"}]'
```

```json
{"status": "received", "count": 1, "event_ids": [42]}
```

A single object returns `{"status": "received", "event_id": 42, "event_type": "bounce"}`.

### Supported providers

`{provider}` selects how the payload is read. Anything not listed uses the `generic` format.

| Provider | Email | Event type | Tracking token |
| :--- | :--- | :--- | :--- |
| `mailgun` | `event-data.recipient` | `event-data.event`: `failed` with `event-data.severity` of `permanent` becomes `hard_bounce`, any other `failed` becomes `soft_bounce` (stored only), and `complained` becomes `complaint` | `event-data.user-variables.odden_token` |
| `ses` | `mail.destination.0` | `eventType`, lowercased (default `bounce`) | `mail.headersTruncated.X-Odden-Token` |
| `postmark` | `Recipient` or `Email` | `RecordType`, lowercased (default `bounce`) | `Metadata.odden_token` |
| `sendgrid` | `email` | `event`: `bounce` and `dropped` become `bounce`, `spamreport` becomes `complaint`, `unsubscribe` becomes `unsubscribed` | `odden_token` |
| `resend` | `data.to.0` | `type`: `email.bounced`, `email.complained`, and `email.delivered` become `bounce`, `complaint`, and `delivered` | `data.tags.odden_token` |
| `generic` | `email` or `recipient` | `event_type`, `type`, or `event` (default `bounce`) | `tracking_token` |

The generic format also reads `error_code` (or `code`) and `error_message` (or `reason`):

```json
{
    "provider": "generic",
    "email": "spam@example.com",
    "event_type": "complaint",
    "error_message": "Marked as spam",
    "tracking_token": "the recipient's tracking_token"
}
```

### What each event does

Every event is stored as an `EspEvent` (`provider`, `event_type`, `email`, `campaign_id`, `recipient_id`, `error_code`, `error_message`, and the raw `payload`). Then:

| Event type | Effect |
| :--- | :--- |
| `bounce`, `hard_bounce` | `MarketingSubscription` status `Bounced`; suppression reason `hard_bounce`; recipient status `Bounced`; campaign `bounces_count` + 1 |
| `complaint`, `spam` | Status `Unsubscribed`; reason `spam_complaint`; recipient status `Unsubscribed`; campaign `unsubscribes_count` + 1 |
| `unsubscribed` | Status `Unsubscribed`; reason `unsubscribe`; recipient status `Unsubscribed`; campaign `unsubscribes_count` + 1 |
| `delivered` | A `Pending` recipient becomes `Sent` |
| anything else | Stored only |

For the first three, the contact also gets an `Unsubscribed` [lead scoring](lead-scoring.md) event, if a recipient was matched.

Check how your provider names its events before relying on this. Only the names in the table above have an effect:

- Postmark's `SpamComplaint` record type is stored but doesn't suppress anyone.
- A temporary Mailgun failure (`soft_bounce`) is stored but doesn't suppress anyone; only a permanent one does.
- Every `bounce` is treated as permanent. SES and Postmark soft (transient) bounces and SendGrid `dropped` events suppress the address too.
- With the `generic`, `ses`, and `postmark` formats, an event with no type is treated as a bounce.

### Authenticating Mailgun webhooks

Mailgun can't send an API token header, and putting `?token=` in its webhook URL leaves a secret in Mailgun's settings and in your access logs. Instead, set the HTTP webhook signing key from the Mailgun dashboard (Webhooks):

```ini
ODDEN_MARKETING_MAILGUN_SIGNING_KEY=your-mailgun-http-webhook-signing-key
```

Then point Mailgun's `permanent_fail` and `complained` webhooks (plus `unsubscribed`, if you use it) at the plain URL, with no token:

```text
https://example.com/marketing/webhooks/esp/mailgun
```

With a signing key set, `POST /marketing/webhooks/esp/mailgun` accepts a request only if Mailgun's `signature` block is valid: the HMAC-SHA256 of the `timestamp` and `token` values, keyed with the signing key. The API token no longer works on that URL, a signature older than 15 minutes (`ODDEN_MARKETING_MAILGUN_SIGNATURE_TOLERANCE`, in seconds) is rejected, and each signature token is accepted once, so a captured request can't be replayed. A failed check returns `401` and records nothing. Replay protection uses your application cache, so use a shared cache store when you run several servers.

Without a signing key, Mailgun webhooks use the API token like every other provider. The `/api/marketing/webhooks/deliverability` endpoint always uses the API token.

### Matching events to recipients

An event is linked to a `CampaignRecipient` by its tracking token if the payload carries one. Otherwise, it's linked to the most recent recipient with the same email address.

To match exactly, pass the recipient's `tracking_token` to your provider as metadata named `odden_token` when you send (Mailgun user variables, Postmark metadata, SendGrid custom args, Resend tags). Campaign messages carry the token in an `X-Odden-Tracking-Token` header, so you can copy it into your provider's metadata in a `MessageSending` listener or your provider's header-mapping settings. For SES, the configured path (`mail.headersTruncated`) is a boolean in SES events, so SES events always fall back to matching by address.

### Amazon SES

The `ses` format reads the SES event object itself (`eventType`, `mail`, `bounce`). Amazon SNS HTTP subscriptions wrap that object in a JSON string inside an SNS envelope, send it as `text/plain`, and require a subscription confirmation. The endpoint handles none of that, so receive SNS notifications in your own route and forward the inner message.

## Linting a campaign

Two actions score a campaign's content before you send it. Neither runs automatically, and neither blocks dispatch.

### `LintCampaignDeliverabilityAction`

```php
use Odden\Marketing\Actions\LintCampaignDeliverabilityAction;

$report = app(LintCampaignDeliverabilityAction::class)->execute($campaign);

$report['score'];   // 0–100
$report['status'];  // 'excellent' (90+), 'good' (75+), 'warning' (50+), or 'critical'
$report['warnings'];        // list of ['rule' => ..., 'message' => ..., 'severity' => 'critical'|'warning']
$report['passed_checks'];   // list of strings
$report['recommendations']; // list of strings
```

`execute(Campaign $campaign, ?string $overrideHtml = null, ?string $overrideSubject = null): array` checks the campaign's subject, preview text, and sender, and its template's `body_html` (or the HTML you pass). It starts at 100 and deducts:

| Rule | Deduction | Fails when |
| :--- | :--- | :--- |
| `sender_email_valid` | 30 | The sender address is missing or invalid |
| `sender_domain_authenticated` | 25 | The sender uses a free mail domain (gmail.com, yahoo.com, outlook.com, …) |
| `subject_required` | 30 | The subject is blank |
| `subject_length` | 10 | The subject is over 60 or under 8 characters |
| `subject_punctuation` | 10 | The subject has `!!`, `??`, or `$$` |
| `subject_spam_words` | 15 | The subject contains a phrase like "100% free", "act now", or "buy now" |
| `preview_text_provided` | 10 | There's no preview text |
| `unsubscribe_compliance` | 30 | The body has neither `{{unsubscribe_url}}` nor the word "unsubscribe" |
| `broken_placeholder_links` | 10 | The body has `href=""` or `href="#"` |
| `text_to_image_ratio` | 15 | The body has images and fewer than 100 characters of text |

### `AuditCampaignDeliverabilityAction`

The Filament plugin's audit modal uses this one. It returns `score`, `rating` (`Excellent`, `Good`, `Fair (Needs Review)`, or `High Spam Risk`), and `checks`, a list of `['name', 'passed', 'severity', 'message']`.

It checks for an unsubscribe link, unbalanced `{{`/`}}` merge tags, spam phrases in the subject or body, a long run of capitals or repeated punctuation in the subject, a consumer sender domain, and a body with fewer than 30 characters of text.

```php
use Odden\Marketing\Actions\AuditCampaignDeliverabilityAction;

$audit = app(AuditCampaignDeliverabilityAction::class)->execute($campaign);
```

## Checking a sending domain

`DomainHealthCheckService::diagnose()` looks up a domain's SPF, DKIM, DMARC, and MX records with `dns_get_record()`:

```php
use Odden\Marketing\Services\DomainHealthCheckService;

$health = app(DomainHealthCheckService::class)->diagnose('acme.com', 'odden');

$health['overall_status']; // 'pass', 'warning', or 'fail'
$health['dmarc']['policy']; // e.g. 'quarantine'
```

The second argument is the DKIM selector (default `odden`), looked up at `{selector}._domainkey.{domain}`. Each of `spf`, `dkim`, `dmarc`, and `mx` has a `status`, a `label`, what was `found`, a `recommendation`, and a `note`.

- DMARC passes with `p=quarantine` or `p=reject`; `p=none` is a warning.
- `overall_status` is `pass` when SPF, DMARC, and MX pass. It's `fail` if SPF or MX is missing, and `warning` otherwise. DKIM doesn't affect it.
- Domains ending in `.test`, and `localhost`, always return a passing result with sample records, without a DNS lookup.

## Throttling by domain

`DomainThrottler` plans sends so no mailbox provider gets too many messages a minute. It only calculates a plan; it doesn't delay or send anything.

```php
use Odden\Marketing\Services\DomainThrottler;

$plan = DomainThrottler::calculateThrottledBatches(
    recipients: [['to' => 'a@gmail.com'], ['to' => 'b@yahoo.com']],
    customDomainLimits: ['acme.com' => 30],
    defaultPerMinute: 120,
);

$plan['waves']; // [['wave_index' => 0, 'offset_seconds' => 0, 'count' => 2, 'recipients' => [...]]]
```

Built-in limits per minute: Yahoo, Ymail and AOL 60; Gmail and Googlemail 120; Hotmail, Outlook, Live and MSN 100; iCloud and me.com 80. The plan also has `total_recipients`, `domain_distribution`, and `estimated_dispatch_duration_seconds`. The transactional batch endpoint returns one with `throttle_domains`; see [Transactional email](transactional-email.md#sending-a-batch).

## Fatigue protection

Fatigue protection caps how many campaign emails one contact gets. It's off by default; see [Campaigns](campaigns.md#fatigue-protection).

## Sunset policy

Sending to people who never open hurts your reputation. The sunset policy finds them and, if you choose, unsubscribes them.

```bash
php artisan marketing:sunset-subscribers --days=90 --min-sends=3
php artisan marketing:sunset-subscribers --days=90 --min-sends=3 --suppress
```

| Option | Default | Meaning |
| :--- | :--- | :--- |
| `--days` | `90` | Inactivity window |
| `--min-sends` | `3` | Campaign emails the contact must have been sent before they can be sunset |
| `--suppress` | off | Unsubscribe dormant contacts instead of only flagging them |

The command runs `ProcessSubscriberSunsetPolicyAction`, which considers contacts who are being mailed but never engage: at least `--min-sends` campaign emails sent, the first one `--days` or more ago, still receiving mail (a send inside the window), and no open or click inside the window. Contacts you stopped mailing are not sunset, and anyone already suppressed is skipped. For each remaining contact:

- Without `--suppress`: it sets `properties.is_sunset_dormant` to `true` and `properties.sunset_dormant_detected_at`.
- With `--suppress`: it unsubscribes the address globally, sets `properties.sunset_suppressed` and `properties.sunset_suppressed_at`, and logs a task on the contact.

It prints a table with the number of candidates, dormant contacts, and suppressed contacts. You can also call the action yourself:

```php
use Odden\Marketing\Actions\ProcessSubscriberSunsetPolicyAction;

$result = app(ProcessSubscriberSunsetPolicyAction::class)->execute(
    inactivityDays: 90,
    minSendsReceived: 3,
    autoSuppress: false,
);

$result['contact_ids']; // the dormant contacts
```

Only contacts you **haven't emailed** for `--days` are candidates. A contact you keep sending to who never opens has a recent `last_marketing_email_sent_at`, so the policy never reaches them. The [installation guide](../installation.md#schedule-the-commands) schedules the command daily without `--suppress`, so by default it only flags.

### Sunset stages

A second set of actions tracks a contact through stages in the `sunset_stage` column. Nothing in the package runs them on a schedule:

- `DetectUnengagedContactsAction::execute(int $daysInactive = 90, int $minSends = 3)` sets `is_unengaged`, `unengaged_since`, and `sunset_stage = 'flagged'` on the same contacts the command considers (mailed, never engaged), skipping suppressed and unsubscribed ones. `candidates()` returns that query without flagging anyone.
- `ExecuteSunsetPolicyAction::execute(Contact $contact, bool $forceSuppress = false)` moves a `flagged` (or unengaged) contact to `reengagement_sent` and queues a re-engagement email (with a link to their preference center), and a `reengagement_sent` contact (or any contact, with `$forceSuppress`) to `suppressed` and unsubscribes the address globally. Each step logs a task on the contact.

The `suppressed` stage also adds the address to the suppression list, so it stops all marketing email whether or not [fatigue protection](campaigns.md#fatigue-protection) is enabled.
