---
title: Email campaigns
description: Create broadcast email campaigns, choose their audience, schedule them in each recipient's time zone, run A/B tests, and track opens and clicks.
---

A campaign is a one-off email to an audience: an `Odden\Marketing\Models\Campaign` with a subject, a sender, a [template](email-templates.md), and a Core list. Dispatching it creates a `CampaignRecipient` row per contact and queues each contact's email; those rows carry the tokens that power open tracking, click tracking, and unsubscribe links.

## Creating a campaign

```php
use Odden\Marketing\Models\Campaign;

$campaign = Campaign::create([
    'name' => 'March newsletter',
    'subject' => 'What shipped in March',
    'preview_text' => 'Three new features and a webinar invite',
    'sender_name' => 'Acme',
    'sender_email' => 'news@acme.test',
    'reply_to_email' => 'support@acme.test',
    'template_id' => $template->id,
    'crm_list_id' => $list->id,
]);
```

`name`, `subject`, `sender_name`, and `sender_email` are required by the database. The `defaults.sender_*` config values aren't applied to new campaigns; messages and [proofs](#sending-a-proof) fall back to them only if a sender is empty.

A new campaign is `CampaignStatus::Draft` and `CampaignType::Regular`. The other `type`, `Automated`, is a label only: nothing in the package treats it differently.

### Attributes

| Attribute | Default | Purpose |
| :--- | :--- | :--- |
| `template_id` | `null` | The [template](email-templates.md) to send. Without one, the body is `<p>{{content}}</p>` |
| `crm_list_id`, `list_id` | `null` | The audience. `crm_list_id` wins if both are set |
| `topic_id` | `null` | A [subscription topic](subscriptions-and-compliance.md#subscription-topics) the recipient must be subscribed to |
| `topic` | `null` | A topic slug matched against the contact's `marketing_topics` |
| `status` | `draft` | A `CampaignStatus` |
| `scheduled_at` | `null` | When `marketing:dispatch-scheduled` should send a `Scheduled` campaign |
| `send_by_timezone`, `send_in_recipient_timezone` | `false` | Deliver at a local time in each recipient's time zone |
| `scheduled_local_time` | `null` | Local send time as `HH:MM` |
| `recipient_send_hour` | `9` | Local send hour when `scheduled_local_time` is empty |
| `use_sto` | `false` | Send-time optimization from each contact's open history |
| `utm_auto_tag` | `true` | Add UTM parameters to links |
| `utm_campaign` | `null` | Value for `utm_campaign`; the campaign name is used when empty |
| `is_ab_test` and the `ab_*` fields | | See [A/B testing](#ab-testing) |
| `budget` | `null` | Planned spend |
| `actual_cost`, `actual_spend` | `0.00` | Actual spend, used by [attribution](attribution.md) ROI reports |
| `target_leads`, `target_pipeline`, `target_revenue` | `null` | Goals |
| `properties` | `null` | Free-form array |

### Metrics

Dispatch, tracking, and webhooks keep these counters up to date: `total_recipients`, `delivered_count`, `opens_count`, `unique_opens_count`, `clicks_count`, `unique_clicks_count`, `bounces_count`, and `unsubscribes_count`.

Four computed attributes read them:

| Attribute | Formula |
| :--- | :--- |
| `open_rate` | `unique_opens_count / delivered_count × 100`, one decimal |
| `click_rate` | `unique_clicks_count / delivered_count × 100` |
| `ctor` | `unique_clicks_count / unique_opens_count × 100` |
| `leads_progress_percentage` | `unique_clicks_count / target_leads × 100` |

```php
$campaign->open_rate; // 42.5
```

## Choosing the audience

Point the campaign at a Core list with `crm_list_id` (or `list_id`). If the list is an active list, dispatch re-evaluates its criteria first, so the audience is current at send time. See [Core concepts](../core/index.md) for lists.

```php
use Odden\Core\Enums\ListType;
use Odden\Core\Models\CrmList;

$list = CrmList::create([
    'name' => 'Engaged leads',
    'entity_type' => 'contact',
    'type' => ListType::Active,
    'criteria' => [
        ['property' => 'lead_score', 'operator' => '>=', 'value' => 50],
    ],
]);

$campaign->update(['crm_list_id' => $list->id]);
```

A campaign needs an audience. If neither `crm_list_id` nor `list_id` is set and you don't pass contacts, dispatch throws `Odden\Marketing\Exceptions\CampaignHasNoAudienceException` and changes nothing; it never falls back to every contact. `marketing:dispatch-scheduled` reports the error, leaves the campaign `Scheduled`, and exits with a failure code, so the campaign goes out on the next run after you assign a list.

You can also pass the contacts yourself. Your collection is used instead of the list's members:

```php
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\DispatchCampaignAction;

$contacts = Contact::query()->where('lifecycle_stage', 'customer')->get();

app(DispatchCampaignAction::class)->execute($campaign, $contacts);
```

### Who is skipped

Dispatch skips, and counts as suppressed, any contact:

- with an empty email address
- whose address is unsubscribed or bounced in `MarketingSubscription`, or on the [suppression list](deliverability.md#the-suppression-list)
- who is unsubscribed from the campaign's `topic_id` topic
- whose `marketing_topics` doesn't include the campaign's `topic` slug (a contact whose `marketing_topics` is `null` receives every topic)
- who fails the [fatigue check](#fatigue-protection), when it's enabled

[Subscriptions and compliance](subscriptions-and-compliance.md) explains how each of these is set. Dispatch doesn't require a [double opt-in](subscriptions-and-compliance.md#double-opt-in) confirmation.

## Dispatching

```php
use Odden\Marketing\Actions\DispatchCampaignAction;

$result = app(DispatchCampaignAction::class)->execute($campaign);

// ['total_recipients' => 2, 'delivered_count' => 1, 'suppressed_count' => 1]
```

`execute(Campaign $campaign, ?Collection $explicitContacts = null): array` sets the campaign to `Sending`, then for each eligible contact:

1. Finds or creates the contact's `CampaignRecipient` (status `Pending`, with a 40-character `tracking_token` and a 40-character `unsubscribe_token`). There's at most one recipient per campaign and contact, enforced by a unique index.
2. Hands the recipient to `DeliverCampaignMessageAction`, which compiles the message with `CompileCampaignMessageAction` (see [The compiled message](#the-compiled-message)) and queues it (see [Delivering the messages](#delivering-the-messages)).
3. Once the message is queued, marks the recipient `Sent` with a `sent_at`, logs a task activity on the contact titled `Marketing Campaign: {name}`, and sets the contact's `last_marketing_email_sent_at`.

When it finishes, it sets `sent_at` (on the first dispatch only), `total_recipients`, and `delivered_count` (the number of recipients sent so far). The status becomes `Sent` if no recipient is left `Pending`, or stays `Sending` if some are waiting for their [local send time](#local-time-and-send-time-optimization) or an [A/B test](#ab-testing) result. In the result, `delivered_count` is the number of messages queued by this call.

Dispatch is idempotent. Dispatching a campaign again doesn't create a second set of recipients, and a recipient that was already sent isn't sent again; only list members who are new since the last dispatch (or who were held back and are now due) get a message. If queueing fails part-way, the exception propagates and the recipient it failed on stays `Pending`, so you can run dispatch again to finish the job.

Dispatch runs in the calling process, one contact at a time. Only compiling and queueing happen there; the queue worker does the sending.

### Delivering the messages

Every send path uses the same delivery: `DispatchCampaignAction`, the local-time release in `marketing:dispatch-scheduled`, and the A/B rollout in `marketing:evaluate-ab-tests` all call `DeliverCampaignMessageAction::execute()` once per recipient. It queues an `Odden\Marketing\Mail\MarketingMessageMailable` with:

- the compiled HTML, and a plain-text alternative generated from it (links become `label (url)`)
- the subject for the recipient's variant, and the campaign's `sender_email`, `sender_name`, and `reply_to_email`
- `List-Unsubscribe` and `List-Unsubscribe-Post: List-Unsubscribe=One-Click` headers pointing at the recipient's [one-click unsubscribe URL](subscriptions-and-compliance.md#one-click-unsubscribe)
- an `X-Odden-Tracking-Token` header with the recipient's `tracking_token`, so your provider's events can be [matched to the recipient](deliverability.md#matching-events-to-recipients)

The mailable implements `ShouldQueue`. It goes on the `odden-marketing.mail` connection and queue through the `odden-marketing.mail.mailer` mailer (each empty by default, meaning your defaults), so you need a queue worker running; see [Sending mail](index.md#sending-mail).

A recipient is sent at most once. `DeliverCampaignMessageAction` claims the recipient row (sets `sent_at`) with a conditional update before it queues the message, so a retry, a re-run, or a second worker finds the row already claimed and sends nothing. If queueing throws, the claim is released and the recipient stays `Pending`. Its `execute()` returns `DeliverCampaignMessageAction::QUEUED`, `ALREADY_SENT`, or `SUPPRESSED`.

Recipients held back for a local send time or an A/B result are checked again when they're released: if the address was unsubscribed, bounced, or suppressed, or unsubscribed from the campaign's `topic_id` or `topic`, in the meantime, nothing is sent and the recipient's status becomes `Suppressed`. Fatigue protection isn't re-applied at release; it was applied at dispatch.

`CompileCampaignMessageAction` still builds the HTML, so you can extend it and bind your class to change the message. If you followed the earlier version of this page and bound a subclass that calls `Mail` itself, remove that binding, or every message is sent twice.

### Sending a proof

`SendCampaignProofAction` queues a test copy of the campaign to the addresses you give it, through the same `odden-marketing.mail` queue and mailer as campaign messages.

```php
use Odden\Marketing\Actions\SendCampaignProofAction;

$result = app(SendCampaignProofAction::class)->execute($campaign, 'me@acme.test, legal@acme.test');

// ['success' => true, 'sent_to' => ['me@acme.test', 'legal@acme.test'], 'message' => 'Proof email queued for me@acme.test, legal@acme.test']
```

`execute(Campaign $campaign, string|array $recipientEmails, ?Contact $sampleContact = null): array` accepts a comma-separated string or an array and drops invalid addresses.

- The mailable is `Odden\Marketing\Mail\CampaignProofMailable` (it implements `ShouldQueue`), and the subject is prefixed with `[TEST] `.
- Merge tags are filled from `$sampleContact`, or the first contact on the campaign's `crm_list_id` list, or the first contact in the database.
- The unsubscribe link points at a placeholder token, and no tracking is added.
- Errors while queueing are caught and returned as `success: false` with the exception message. Delivery errors happen later, in the queue worker.

## Scheduling

To send later, set the status to `Scheduled` and a `scheduled_at`:

```php
use Odden\Marketing\Enums\CampaignStatus;

$campaign->update([
    'status' => CampaignStatus::Scheduled,
    'scheduled_at' => now()->addDay()->setTime(9, 0),
]);
```

`marketing:dispatch-scheduled` does two things each run:

1. Dispatches every `Scheduled` campaign whose `scheduled_at` is now or earlier.
2. For every `Sending` campaign with `send_by_timezone`, `send_in_recipient_timezone`, or `use_sto` on, it releases each `Pending` recipient whose `scheduled_send_at` is past or within five minutes, queueing its message through [the same delivery](#delivering-the-messages). When a campaign has no pending recipients left, it's marked `Sent`.

Schedule it every minute; see [Installation](../installation.md#schedule-the-commands). To stop a scheduled campaign, change its status, for example to `CampaignStatus::Cancelled`.

## Local time and send-time optimization

Turn on `send_by_timezone` (or the older `send_in_recipient_timezone`) to deliver at the same local time in each recipient's time zone:

```php
$campaign->update([
    'status' => CampaignStatus::Scheduled,
    'scheduled_at' => now(),
    'send_by_timezone' => true,
    'scheduled_local_time' => '09:00',
]);
```

When the campaign is dispatched, `CalculateRecipientOptimalSendTimeAction` works out each contact's send time. Recipients whose time is more than five minutes away are stored as `Pending` with a `scheduled_send_at`, and `marketing:dispatch-scheduled` releases them later. The rest are queued straight away.

The send time is calculated like this:

1. **Time zone.** The contact's `timezone` attribute or `properties['timezone']`, if it's a valid identifier. Otherwise the contact's `country` (or `properties['country']`) is mapped to a time zone for a fixed set of codes: `US`, `USA`, `GB`, `UK`, `DE`, `FR`, `NL`, `AU`, `CA`, `JP`, `IN`, `SG`, `NZ`, and `BR`. Otherwise `app.timezone`.
2. **Hour.** With `use_sto` on, the hour (in the contact's time zone) at which the contact has opened the most campaigns, or `recipient_send_hour` if the contact has never opened one. Otherwise `scheduled_local_time` if set, then `recipient_send_hour`, then 9:00.
3. **Day.** The local calendar day of `scheduled_at`, or of now if it's empty. If that time has already passed by more than 15 minutes, the next day.

You can call the calculation yourself. It returns a UTC time:

```php
$sendAt = $campaign->calculateScheduledTimeForContact($contact);
```

Send-time optimization turns on the same local-time release, so a `use_sto` campaign also needs `marketing:dispatch-scheduled`.

## A/B testing

An A/B campaign sends two variants to a sample of the audience, waits, then sends the better one to everyone else.

```php
$campaign = Campaign::create([
    'name' => 'Spring launch',
    'subject' => 'Our spring launch',
    'variant_b_subject' => 'You asked, we built it',
    'variant_b_template_id' => $otherTemplate->id, // optional
    'sender_name' => 'Acme',
    'sender_email' => 'news@acme.test',
    'template_id' => $template->id,
    'crm_list_id' => $list->id,
    'is_ab_test' => true,
    'ab_test_sample_percentage' => 40,
    'ab_test_duration_hours' => 4,
    'ab_winning_metric' => 'click_rate',
]);
```

| Attribute | Default | Purpose |
| :--- | :--- | :--- |
| `is_ab_test` | `false` | Turn on A/B mode |
| `variant_b_subject` | `null` | Subject line for variant B |
| `variant_b_template_id` | `null` | Template for variant B. Without it, variant B uses the campaign template's own [variant B](email-templates.md#variant-b) |
| `ab_test_sample_percentage` | `20` | Share of the eligible audience in the test |
| `ab_test_duration_hours` | `4` | Hours to wait after `sent_at` before picking a winner |
| `ab_winning_metric` | `open_rate` | `open_rate` or `click_rate` |
| `ab_winner_variant` | `null` | Set to `A` or `B` once evaluated |
| `ab_test_evaluated_at` | `null` | When the winner was picked |

On dispatch, contacts are filtered with the same checks as a standard broadcast (suppression, topic, and [fatigue protection](#fatigue-protection) when it's on). The sample is then rounded to an even number of at least two and split in half: the first half gets variant A and the second variant B. Everyone else is stored as a `Pending` recipient with no variant, and the campaign stays `Sending`. With [local-time sending](#local-time-and-send-time-optimization) on, a sample recipient whose local time hasn't come is stored `Pending` with its variant and `scheduled_send_at`, and `marketing:dispatch-scheduled` sends it that variant when the time arrives.

With 10 eligible contacts and a 40% sample, 2 get A, 2 get B, and 6 wait.

`marketing:evaluate-ab-tests` looks at every `Sending` A/B campaign without a winner whose `sent_at` plus `ab_test_duration_hours` has passed. For each one it runs `EvaluateAbTestWinnerAction`, which:

- compares the open or click rate of the two variants among the recipients already sent (B must be strictly higher to win; on a tie the control, A, is rolled out and the result has `tie` set to `true`)
- queues the winning variant to every staged recipient (the pending ones without a variant) through [the same delivery](#delivering-the-messages), or, with local-time sending on, stores the winner on the recipient with its local `scheduled_send_at` until that time; each sent recipient is marked `Sent` and logging a task on the contact (recipients who unsubscribed during the test are marked `Suppressed` instead)
- sets `ab_winner_variant`, `ab_test_evaluated_at`, and status `Sent`

You can run the evaluation yourself at any time:

```php
use Odden\Marketing\Actions\EvaluateAbTestWinnerAction;

$result = app(EvaluateAbTestWinnerAction::class)->execute($campaign);

// ['winner' => 'B', 'metric' => 'click_rate', 'variant_a_score' => 0.0, 'variant_b_score' => 50.0, 'remaining_sent' => 6, 'tie' => false]
```

Calling it on a campaign that already has a winner, or isn't an A/B test, changes nothing.

`marketing:evaluate-ab-tests` prints a warning instead of a win when the variants tied, and a tie is also written to the log. Before a winner exists, `marketing:dispatch-scheduled` only releases the test sample, never the staged recipients.

### Significance

`Odden\Marketing\Services\AbTestSignificanceCalculator` runs a two-tailed two-proportion z-test if you want to report confidence alongside the winner. It isn't used by the evaluation above.

```php
use Odden\Marketing\Services\AbTestSignificanceCalculator;

$stats = AbTestSignificanceCalculator::calculate(
    sampleA: 500, conversionsA: 60,
    sampleB: 500, conversionsB: 85,
    confidenceThreshold: 0.95,
);

$stats['is_significant']; // true
$stats['winning_variant']; // 'B'
```

The result also includes `rate_a`, `rate_b`, `relative_uplift_percent`, `z_score`, `p_value`, `confidence_percent`, and a `recommendation` string.

### Subject line suggestions

`GenerateAiSubjectLinesAction::execute(string $topic, string $tone = 'engaging', ?string $audience = null)` returns `suggestions` (three strings), a `variant_b` candidate, `preview_text`, and a `rationale`. Despite its name, it fills in fixed phrase templates and doesn't call an AI model. Tones are `urgent`, `curious`, `friendly`, and `bold`; anything else uses the default set.

## Fatigue protection

Fatigue protection stops a contact from getting campaign email too often. It's off by default.

```env
MARKETING_FATIGUE_PROTECTION_ENABLED=true
MARKETING_MAX_EMAILS_7_DAYS=2
MARKETING_MIN_HOURS_BETWEEN_SENDS=24
```

When it's on, standard (non-A/B) dispatch runs `CheckFatiguePolicyAction` for each contact and skips anyone who:

- has `sunset_stage` set to `suppressed` (see [Deliverability](deliverability.md#sunset-policy))
- was sent a campaign email less than `min_hours_between_sends` hours ago, by `last_marketing_email_sent_at`
- has `max_emails_per_7_days` or more non-pending campaign recipients with `sent_at` in the last 7 days

You can check a contact yourself:

```php
use Odden\Marketing\Actions\CheckFatiguePolicyAction;

$check = app(CheckFatiguePolicyAction::class)->execute($contact);

// ['can_send' => false, 'reason' => 'Contact received marketing email 3h ago (minimum interval: 24h)', 'next_available_at' => Carbon]
```

## The compiled message

`CompileCampaignMessageAction::execute(Campaign $campaign, CampaignRecipient $recipient): string` builds the HTML for one recipient, in this order:

1. **Body.** If the template has mail builder slots, they're compiled for this recipient, so [slot visibility rules](email-templates.md#conditional-slots) are applied against the contact and their first company. Otherwise the template's `body_html` is used (or the variant B HTML for a variant B recipient).
2. **Merge tags.** Every tag in the table below is replaced through the mail builder's interpolator, so [filters, spaces inside the braces and conditionals](email-templates.md#in-the-mail-builder) work too. A tag nobody registered is left in the email as written. Values are HTML-escaped with `e()`, so a contact named `<b>Sam</b>` appears as that literal text rather than as markup.

   | Tag | Value |
   | :--- | :--- |
   | `{{contact.first_name}}` | First name, or `there` |
   | `{{contact.last_name}}` | Last name, or empty |
   | `{{contact.email}}` | The recipient's address |
   | `{{company.name}}` | The contact's first company, or `your organization` |
   | `{{unsubscribe_url}}` | This recipient's [unsubscribe page](subscriptions-and-compliance.md#unsubscribe-links) |
   | `{{campaign.subject}}` | The campaign subject |
   | `{{campaign.name}}` | The campaign name |
   | `{{contact.full_name}}` | First and last name together |
   | `{{contact.job_title}}`, `{{contact.phone}}`, `{{contact.lifecycle_stage}}` | The contact's field, or empty |
   | `{{company.domain}}`, `{{company.industry}}` | The first company's field, or empty |
   | `{{sender.name}}` | The contact's owner, or the campaign's `sender_name` |
   | `{{sender.email}}` | The campaign's `sender_email` |

3. **Smart content.** `[smart]` blocks and `{{smart:…}}` tokens are resolved for the contact; see [Smart content](email-templates.md#smart-content).
4. **UTM parameters.** When `utm_auto_tag` is on, `utm_source=odden`, `utm_medium=email`, and `utm_campaign` (the slug of `utm_campaign`, or of the campaign name) are added to every absolute link, plus `utm_content=variant_a` or `variant_b` for A/B recipients. Parameters already in a link keep their value. `mailto:`, `tel:`, `#` and unsubscribe links are left alone.
5. **Click tracking.** Every link except `mailto:`, `tel:`, `#` and unsubscribe links is rewritten to the recipient's [click-tracking URL](#tracking-opens-and-clicks).
6. **Open pixel.** A 1×1 image pointing at the recipient's open-tracking URL is added before `</body>`, or at the end.

Both link steps read each `href` as HTML: they decode it to the real URL (so `&amp;` in your template means `&`), work on that, and write the result back escaped once. The UTM parameters are appended after the link's own query string, which is kept exactly as written. So a template link `https://acme.test/sale?ref=news&amp;id=5` in a campaign named "Spring Sale" redirects, after the click is recorded, to `https://acme.test/sale?ref=news&id=5&utm_source=odden&utm_medium=email&utm_campaign=spring-sale`.

## Tracking opens and clicks

Each recipient has three links, built from its tokens:

```php
$recipient->getTrackingPixelUrl();                       // route('odden.marketing.track.open', $token)
$recipient->getClickRedirectUrl('https://acme.test/x');  // route('odden.marketing.track.click', ['token' => ..., 'url' => ..., 'sig' => ...])
$recipient->getUnsubscribeUrl();                         // route('odden.marketing.unsubscribe.show', $unsubscribeToken)
```

**Opens.** `GET /marketing/track/open/{token}` returns a transparent GIF with no-cache headers. For a known token it calls `$recipient->recordOpen()`: status becomes `Opened` (unless it is already `Clicked`, or a final status such as `Bounced`), `opened_at` is set on the first open, `opens_count` increases on every request, and `unique_opens_count` on the first.

**Clicks.** `GET /marketing/track/click/{token}?url=...&sig=...` redirects only to destinations your app signed. `sig` is an HMAC-SHA256, keyed with `app.key`, over the token and the destination URL; `getClickRedirectUrl()` adds it, and `CampaignRecipient::clickSignature($token, $url)` computes it. When the signature matches and `url` is an `http` or `https` URL, the endpoint calls `$recipient->recordClick()` for a known token in the same way (status `Clicked`, `clicked_at`, `clicks_count`, `unique_clicks_count`), then redirects to `url`. Anything else (a missing or wrong `sig`, a changed `url` or token, or another scheme) returns `404` and records nothing.

Because the signature depends on `app.key`, keep the old key in `APP_PREVIOUS_KEYS` when you rotate it: signatures are checked against the current key and each previous key, so links in emails you've already sent keep working.

Both also apply a [lead scoring](lead-scoring.md) event to the contact: `EmailOpened` or `EmailClicked`.

Things to know:

- `status` only moves forward (`Pending`, `Sent`, `Opened`, `Clicked`): an open after a click keeps `Clicked`, and `Bounced`, `Unsubscribed` and `Suppressed` are never overwritten by an open or click. `opened_at` and `clicked_at` still record every first engagement.
- Image proxies and privacy features that prefetch images record opens that the recipient didn't make.
