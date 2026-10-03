---
title: Marketing
description: What the getodden/crm-marketing package adds, its models, scheduled commands, configuration, and routes.
---

`getodden/crm-marketing` adds email marketing and lead capture to Odden. It stores everything in your database, links every record to Core's contacts, companies, and lists, and builds its emails with [`getodden/mail`](email-templates.md).

```bash
composer require getodden/crm-marketing
php artisan migrate
```

The package registers `Odden\Marketing\MarketingServiceProvider` through package discovery. See [Installation](../installation.md) for the full setup, and [Core concepts](../core/index.md) for contacts, companies, and lists.

## The two halves

The module has an email side and a lead side.

**Email**

- [Campaigns](campaigns.md): audiences, scheduling, local-time and send-time optimization, A/B tests, proofs, open and click tracking
- [Email templates](email-templates.md): templates built from mail builder slots, revisions, translations, merge tags, smart content, dynamic images, and AMP
- [Subscriptions and compliance](subscriptions-and-compliance.md): unsubscribe links, the preference center, subscription topics, and double opt-in
- [Deliverability](deliverability.md): the suppression list, ESP bounce and complaint webhooks, content linting, domain checks, and the sunset policy
- [Transactional email](transactional-email.md): the HTTP API that sends a template to one recipient or a batch

**Leads**

- [Forms and landing pages](forms-and-landing-pages.md)
- [Web tracking](web-tracking.md)
- [Lead scoring](lead-scoring.md)
- [Workflows](workflows.md)
- [Events and gated assets](events-and-assets.md)
- [Inbound webhooks and events API](inbound-webhooks.md)
- [Attribution](attribution.md)

## Models

All models live in `Odden\Marketing\Models`. The email side uses these:

| Model | Table (config key) | What it holds |
| :--- | :--- | :--- |
| `Campaign` | `odden_marketing_campaigns` (`tables.campaigns`) | A broadcast email: subject, sender, template, audience, schedule, A/B settings, counters |
| `CampaignRecipient` | `odden_marketing_campaign_recipients` (`tables.recipients`) | One row per contact a campaign was sent or staged for, with its tracking and unsubscribe tokens |
| `MarketingTemplate` | `odden_marketing_templates` (`tables.templates`) | Reusable email content: mail builder slots, compiled HTML and text, variant B |
| `MarketingTemplateRevision` | `odden_marketing_template_revisions` | A snapshot of a template, taken on every save |
| `MarketingTemplateTranslation` | `odden_marketing_template_translations` | Per-locale subject, preview text, and body for a template |
| `MarketingSavedBlock` | `odden_marketing_saved_blocks` | A reusable slot saved for the template editor |
| `MarketingSubscription` | `odden_marketing_subscriptions` (`tables.subscriptions`) | Global subscription status per email address |
| `MarketingSubscriptionTopic` | `odden_marketing_subscription_topics` (`tables.subscription_topics`) | A communication topic shown in the preference center |
| `MarketingContactTopic` | `odden_marketing_contact_topics` (`tables.contact_topics`) | An email address's opt-in or opt-out for one topic |
| `EmailSuppression` | `odden_marketing_suppressions` (`tables.suppressions`) | The global do-not-send list |
| `EspEvent` | `odden_marketing_esp_events` (`tables.esp_events`) | Every event received from an email provider webhook |

The lead side adds forms, landing pages, visitor sessions, scoring rules, workflows, events, assets, NPS surveys, and SMS messages. Their pages document them.

Tables without a config key use fixed names.

### Enums

| Enum | Cases |
| :--- | :--- |
| `CampaignStatus` | `Draft`, `Scheduled`, `Sending`, `Sent`, `Cancelled` |
| `CampaignType` | `Regular`, `Automated` |
| `RecipientStatus` | `Pending`, `Sent`, `Opened`, `Clicked`, `Bounced`, `Unsubscribed`, `Suppressed` |
| `SubscriptionStatus` | `Subscribed`, `Unsubscribed`, `Bounced` |

Each enum has `getLabel()`, and all but `CampaignType` have `getColor()` (a Filament color name).

### Relations added to Contact

When the package boots, it adds these relations to `Odden\Core\Models\Contact`:

| Relation | Returns |
| :--- | :--- |
| `campaignRecipients` | `HasMany` of `CampaignRecipient` |
| `marketingSubscription` | `HasOne` of `MarketingSubscription` |
| `formSubmissions` | `HasMany` of `FormSubmission` |
| `leadScoreLogs`, `leadDecayLogs` | `HasMany`, newest first |
| `workflowEnrollments` | `HasMany` of `WorkflowEnrollment` |
| `customBehavioralEvents` | `HasMany` of `CustomBehavioralEvent` |

When Core [merges two contacts](../core/duplicates-and-merging.md#what-each-module-moves), Marketing moves every row it keys to the duplicate contact over to the surviving one, and on a company merge it moves custom behavioural events. Where both contacts have a row that can only exist once, such as two recipients of the same campaign, it keeps one. Opt-outs carry over: see [Merging contacts](subscriptions-and-compliance.md#merging-contacts).

Core's `contacts` table also carries the marketing columns this package migrates in: `marketing_topics`, `marketing_verification_token`, `marketing_confirmation_token`, `marketing_email_verified_at`, `last_marketing_email_sent_at`, `is_unengaged`, `unengaged_since`, and `sunset_stage`.

## Sending mail

Every email the package sends is queued, never sent during the request or command that triggers it:

- campaign messages, from `DispatchCampaignAction`, `marketing:dispatch-scheduled`, and `marketing:evaluate-ab-tests` (see [Campaigns](campaigns.md#delivering-the-messages))
- workflow [`send_email`](workflows.md#step-types) steps
- campaign proofs (`SendCampaignProofAction`)
- the [transactional API](transactional-email.md)

**Run a queue worker**, or nothing is delivered. Every marketing mailable (`MarketingMessageMailable`, `CampaignProofMailable`, and `TransactionalTemplateMailable`) is pushed to the queue only after the surrounding database transaction commits, like Sales and Service mail, so mail queued inside a transaction that rolls back is never sent. The mail goes on your default queue connection and queue unless you set `ODDEN_MARKETING_MAIL_CONNECTION` and `ODDEN_MARKETING_MAIL_QUEUE`, and through your default mailer unless you set `ODDEN_MARKETING_MAILER`:

```env
ODDEN_MARKETING_MAIL_QUEUE=marketing-mail
ODDEN_MARKETING_MAILER=ses
```

```bash
php artisan queue:work --queue=marketing-mail,default
```

With the `sync` queue connection, messages are sent as they're queued, inside the request or command. That works for development but not for a real list.

## Scheduled commands

The package registers five Artisan commands but doesn't schedule them. Add them to `routes/console.php` as shown in [Installation](../installation.md#schedule-the-commands).

| Command | What it does | Documented in |
| :--- | :--- | :--- |
| `marketing:dispatch-scheduled` | Dispatches `Scheduled` campaigns whose `scheduled_at` has passed, and releases pending local-time and send-time-optimized recipients whose time has come | [Campaigns](campaigns.md#scheduling) |
| `marketing:evaluate-ab-tests` | Picks the winner of A/B campaigns whose test window has ended and releases the rest of the audience | [Campaigns](campaigns.md#ab-testing) |
| `marketing:sunset-subscribers {--days=90} {--min-sends=3} {--suppress}` | Flags, or with `--suppress` unsubscribes, contacts with no opens or clicks | [Deliverability](deliverability.md#sunset-policy) |
| `marketing:process-workflows` | Advances workflow enrollments | [Workflows](workflows.md) |
| `marketing:decay-lead-scores {--days=30} {--points=5}` | Lowers the scores of inactive contacts | [Lead scoring](lead-scoring.md) |

## Configuration

Publish the config file to change any of these:

```bash
php artisan vendor:publish --tag=odden-marketing-config
```

| Key | Default | Environment variable | What it does |
| :--- | :--- | :--- | :--- |
| `tables.*` | `odden_marketing_*` names | | Table names for the models listed above and the lead-side models |
| `defaults.sender_name` | `Odden Marketing` | `MARKETING_FROM_NAME` | Sender name for campaign messages and proofs when the campaign has none, and for workflow emails |
| `defaults.sender_email` | `newsletter@odden.test` | `MARKETING_FROM_EMAIL` | Sender address for campaign messages and proofs when the campaign has none, and for workflow emails |
| `defaults.reply_to` | `support@odden.test` | `MARKETING_REPLY_TO` | Reply-to address for campaign proofs when the campaign has none, and for workflow emails |
| `mail.mailer` | `null` | `ODDEN_MARKETING_MAILER` | Mailer (from `config/mail.php`) for all marketing mail. Empty uses the default mailer |
| `mail.connection` | `null` | `ODDEN_MARKETING_MAIL_CONNECTION` | Queue connection for marketing mail. Empty uses the default connection |
| `mail.queue` | `null` | `ODDEN_MARKETING_MAIL_QUEUE` | Queue name for marketing mail. Empty uses the connection's default queue |
| `fatigue_protection.enabled` | `false` | `MARKETING_FATIGUE_PROTECTION_ENABLED` | Skip contacts who were emailed too recently during campaign dispatch |
| `fatigue_protection.max_emails_per_7_days` | `2` | `MARKETING_MAX_EMAILS_7_DAYS` | Campaign emails a contact may receive in a rolling 7 days |
| `fatigue_protection.min_hours_between_sends` | `24` | `MARKETING_MIN_HOURS_BETWEEN_SENDS` | Minimum hours between two campaign emails to one contact |
| `sales_handoff.auto_handoff_on_sql` | `true` | `MARKETING_AUTO_HANDOFF_ON_SQL` | See [Lead scoring](lead-scoring.md) |
| `sales_handoff.sql_score_threshold` | `100` | `MARKETING_SQL_THRESHOLD` | See [Lead scoring](lead-scoring.md) |
| `sales_handoff.default_deal_amount` | `10000.00` | `MARKETING_HANDOFF_DEAL_AMOUNT` | See [Lead scoring](lead-scoring.md) |
| `routes.enabled` | `true` | `ODDEN_MARKETING_ROUTES_ENABLED` | Register the package's routes |
| `routes.web.domain` | `null` | `ODDEN_MARKETING_DOMAIN` | Domain for the `web` route group |
| `routes.web.prefix` | `''` | `ODDEN_MARKETING_PREFIX` | Path prefix for the `web` route group |
| `routes.web.middleware` | `['web']` | | Middleware for the `web` route group |
| `routes.api.domain` | `null` | `ODDEN_MARKETING_DOMAIN` | Domain for the `api` route group |
| `routes.api.prefix` | `api/marketing` | `ODDEN_MARKETING_API_PREFIX` | Path prefix for the `api` route group |
| `routes.api.middleware` | `['web']` | | Middleware for the `api` route group |
| `api.token` | `null` | `ODDEN_MARKETING_API_TOKEN` | Shared secret for the server-to-server endpoints |
| `esp.mailgun.signing_key` | `null` | `ODDEN_MARKETING_MAILGUN_SIGNING_KEY` | Mailgun HTTP webhook signing key. When set, Mailgun's ESP webhook is authenticated by signature instead of the API token |
| `esp.mailgun.tolerance` | `900` | `ODDEN_MARKETING_MAILGUN_SIGNATURE_TOLERANCE` | Seconds a Mailgun webhook signature stays valid |
| `amp.allowed_origins` | `https://mail.google.com`, `https://outlook.live.com`, `https://mail.yahoo.com`, `https://mail.aol.com` | `ODDEN_MARKETING_AMP_ALLOWED_ORIGINS` (comma-separated) | Email client origins allowed to call the [AMP endpoints](email-templates.md#amp-for-email) |
| `webhooks.outbound_url` | `null` | `ODDEN_MARKETING_WEBHOOK_URL` | Fallback URL for outbound webhook notifications when a request doesn't pass `webhook_url` |
| `webhooks.secret` | `null` | `ODDEN_MARKETING_WEBHOOK_SECRET` | Fallback signing secret for outbound webhooks when a request doesn't pass `webhook_secret`. With no secret at all, the webhook isn't sent |

See [Transactional email](transactional-email.md#webhook-notifications) for how the `webhooks.*` keys are used.

## Routes

Routes are registered in two groups whose attributes come from `odden-marketing.routes.web` and `odden-marketing.routes.api`. Empty values are dropped, so the default `web` group has no prefix and no domain. [Configuration](../configuration.md#public-routes) explains the shared route options.

If you set `routes.enabled` to `false`, register your own routes with the same names: models and emails build their links with `route('odden.marketing.…')`.

### `web` group

These are the routes for the email side. The group also holds the hosted form, landing page, web tracking, NPS, and asset download routes, documented on their own pages.

| Method | URI | Name | Notes |
| :--- | :--- | :--- | :--- |
| `GET` | `/marketing/track/open/{token}` | `odden.marketing.track.open` | Open-tracking pixel |
| `GET` | `/marketing/track/click/{token}` | `odden.marketing.track.click` | Click redirect, destination in `?url=`, signed with `?sig=`. Unsigned links return `404` |
| `GET` | `/marketing/unsubscribe/{token}` | `odden.marketing.unsubscribe.show` | Unsubscribe confirmation page |
| `POST` | `/marketing/unsubscribe/{token}` | `odden.marketing.unsubscribe.process` | `throttle:odden-public`, CSRF protected |
| `GET` | `/marketing/preferences/{token}` | `odden.marketing.preferences.show` | Preference center |
| `POST` | `/marketing/preferences/{token}` | `odden.marketing.preferences.update` | `throttle:odden-public`, CSRF protected |
| `GET` | `/marketing/confirm/{token}` | `odden.marketing.confirm` | Double opt-in confirmation |
| `POST` | `/marketing/webhooks/esp/{provider}` | `odden.marketing.webhooks.esp` | API token, `throttle:odden-api`, CSRF exempt |
| `GET` | `/marketing/images/countdown-timer.svg` | `odden.marketing.images.countdown-timer` | Dynamic SVG image |
| `GET` | `/marketing/images/badge.svg` | `odden.marketing.images.badge` | Dynamic SVG image |

### `api` group

Every route in this group is exempt from CSRF verification. The default prefix is `api/marketing`.

| Method | URI | Name | Protection |
| :--- | :--- | :--- | :--- |
| `POST` | `/templates/{template}/send` | `odden.marketing.templates.send` | API token, `throttle:odden-api` |
| `POST` | `/templates/{template}/send-batch` | `odden.marketing.templates.send-batch` | API token, `throttle:odden-api` |
| `POST` | `/webhooks/deliverability` | `odden.marketing.webhooks.deliverability` | API token, `throttle:odden-api` |
| `POST` | `/amp/feedback` | `odden.marketing.amp.feedback` | `throttle:odden-public`, `amp.allowed_origins` |
| `POST` | `/amp/rsvp` | `odden.marketing.amp.rsvp` | `throttle:odden-public`, `amp.allowed_origins`, signed RSVP token |
| `POST` | `/forms/{slug}` | `odden.marketing.forms.api-submit` | `throttle:odden-public` |
| `POST` | `/events/{slug}/register` | `odden.marketing.events.register` | `throttle:odden-public` |
| `POST` | `/leads/webhook/{source?}` | `odden.marketing.leads.webhook` | API token, `throttle:odden-api` |
| `POST` | `/events/{slug}/attendance-webhook` | `odden.marketing.events.attendance-webhook` | API token, `throttle:odden-api` |
| `POST` | `/events/track` | `odden.marketing.events.track` | API token, `throttle:odden-api` |
| `POST` | `/workflows/{workflow}/enroll` | `odden.marketing.workflows.enroll-webhook` | API token, `throttle:odden-api` |

### The API token

Every route marked "API token" requires `ODDEN_MARKETING_API_TOKEN`:

```env
ODDEN_MARKETING_API_TOKEN=a-long-random-string
```

Generate one with `php -r 'echo bin2hex(random_bytes(32));'`. Send it as `Authorization: Bearer <token>`, an `X-Odden-Token` header, or a `?token=` query parameter.

The endpoints fail closed: while the token is empty they return `403`, and a missing or wrong token returns `401`. [Configuration](../configuration.md#api-tokens) covers the token and the `odden-public` and `odden-api` rate limiters in detail.

## Admin screens

If you use the [Filament plugin](../filament/index.md), it provides resources for campaigns and templates, a deliverability audit, proof sending, and a sender domain health page, all built on the classes these pages describe.
