---
title: Subscriptions and compliance
description: How unsubscribe links, the preference center, subscription topics, and double opt-in work, and how campaigns respect them.
---

Odden tracks consent per email address, in three layers:

- a global subscription status
- per-topic preferences that people manage in a hosted preference center
- an optional double opt-in confirmation

Campaign dispatch checks the first two before it creates a recipient, and checks them again when a recipient held back for a [local send time](campaigns.md#local-time-and-send-time-optimization) or an [A/B test](campaigns.md#ab-testing) is finally sent, so an unsubscribe in between is honored. The [suppression list](deliverability.md#the-suppression-list) adds a fourth check for addresses that bounced or complained. Workflow [`send_email`](workflows.md#step-types) steps check the global status and the suppression list too.

## Global subscription status

`Odden\Marketing\Models\MarketingSubscription` holds one row per email address (unique), with a `status` (`SubscriptionStatus::Subscribed`, `Unsubscribed`, or `Bounced`), `unsubscribed_at`, and an optional `contact_id`. A contact's row is available as `$contact->marketingSubscription`.

```php
use Odden\Marketing\Models\MarketingSubscription;

MarketingSubscription::unsubscribe('pat@example.com', $contact->id);

MarketingSubscription::isSuppressed('pat@example.com');                     // true
MarketingSubscription::isSuppressed('pat@example.com', 'product_updates');  // also checks one topic
```

`isSuppressed(string $email, int|string|null $topicIdOrSlug = null): bool` returns `true` when:

- the address's status is `Unsubscribed` or `Bounced`, or
- the address is on the [suppression list](deliverability.md#the-suppression-list), or
- a topic is given and the address isn't [subscribed to it](#subscription-topics)

Addresses are lowercased and trimmed everywhere. An address with no row counts as subscribed.

There's no helper to subscribe someone again. To do it, set the row's status yourself, and remove the address from the suppression list if it's there:

```php
use Odden\Marketing\Enums\SubscriptionStatus;
use Odden\Marketing\Models\EmailSuppression;

MarketingSubscription::query()
    ->where('email', 'pat@example.com')
    ->update(['status' => SubscriptionStatus::Subscribed, 'unsubscribed_at' => null]);

EmailSuppression::remove('pat@example.com');
```

## Unsubscribe links

Every campaign recipient gets its own unsubscribe link. Put `{{unsubscribe_url}}` in your template (the mail builder `footer` slot already does); campaigns replace it with:

```php
$recipient->getUnsubscribeUrl(); // route('odden.marketing.unsubscribe.show', $recipient->unsubscribe_token)
```

- `GET /marketing/unsubscribe/{token}` shows a confirmation page with a button. An unknown token returns `404`.
- `POST /marketing/unsubscribe/{token}` processes it:
  1. Unsubscribes the address globally with `MarketingSubscription::unsubscribe()`.
  2. Sets the recipient's status to `Unsubscribed` and increments the campaign's `unsubscribes_count` (only once per recipient).
  3. Applies an `Unsubscribed` [lead scoring](lead-scoring.md) event to the contact.
  4. Shows a confirmation page with status `200`.

The link unsubscribes from all marketing email, not from one topic. To let people choose, link to the [preference center](#preference-center) as well.

### One-click unsubscribe

Gmail and Yahoo expect bulk senders to support one-click unsubscribe (RFC 8058). Every campaign email carries both headers, pointing at the recipient's unsubscribe URL:

```text
List-Unsubscribe: <https://example.com/marketing/unsubscribe/{token}>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

```php
$recipient->getOneClickUnsubscribeUrl(); // route('odden.marketing.unsubscribe.process', $recipient->unsubscribe_token)
```

It's the same URL as the unsubscribe link. A mail client that opens it gets the confirmation page; a mailbox provider's one-click `POST` (body `List-Unsubscribe=One-Click`) unsubscribes straight away and gets `200`. Repeating the `POST` is harmless.

The `POST` route is exempt from CSRF verification, because providers send it without a session or CSRF token. The 40-character unsubscribe token in the URL is the credential, and an unknown token returns `404`. Because one provider sends many of these requests from a few addresses, the route uses the `odden-api` rate limit (`ODDEN_API_RATE_LIMIT`, 600 a minute per IP) rather than `odden-public`.

[Workflow emails](workflows.md#step-types) carry `List-Unsubscribe` with the contact's [preference center](#preference-center) URL and no `List-Unsubscribe-Post`, since the preference center has no one-click endpoint.

## Preference center

The preference center lets a contact choose topics or opt out of everything.

```php
use Odden\Marketing\Support\ContactPreferences;

$url = ContactPreferences::preferenceCenterUrl($contact);
// e.g. https://example.com/marketing/preferences/{token}
```

`ContactPreferences::preferenceCenterUrl()` (`Odden\Marketing\Support\ContactPreferences`) generates the contact's `marketing_verification_token` the first time it's called and saves it quietly. The preference routes accept that token or any `CampaignRecipient` unsubscribe token for the contact.

**`GET /marketing/preferences/{token}`** lists every `MarketingSubscriptionTopic` with the contact's current choices.

> If no topics exist yet, the first visit creates four: `product_updates`, `newsletter`, `webinars`, and `security`. Create your own topics before you send preference links if you don't want these.

An unknown token still renders the page, with no contact and no saved choices.

**`POST /marketing/preferences/{token}`** saves the form and redirects back with a `success` flash message. An unknown token returns `404`.

| Field | Effect |
| :--- | :--- |
| `topics[]` | Topic slugs or ids to stay subscribed to. Every topic not listed is unsubscribed |
| `opt_out_all` | When truthy, unsubscribes the address globally and sets the contact's `marketing_topics` to `[]`; `topics` is ignored |

When saving topics, the controller writes a `MarketingContactTopic` row for every topic and sets the contact's `marketing_topics` to the submitted values.

### Customizing the pages

The pages are Blade views in the `odden-marketing` namespace. The package doesn't publish them, but Laravel loads your copy first if you create it under `resources/views/vendor/odden-marketing/`:

| View | Page |
| :--- | :--- |
| `unsubscribe/show.blade.php` | Unsubscribe confirmation (receives `$recipient`) |
| `unsubscribe/confirmed.blade.php` | After unsubscribing (receives `$email`) |
| `preferences.blade.php` | Preference center (receives `$contact`, `$topics`, `$currentTopics`, `$token`, `$isSuppressed`) |
| `confirmed.blade.php` | Double opt-in confirmation (receives `$contact`) |

Keep the form actions pointed at the `odden.marketing.unsubscribe.process` and `odden.marketing.preferences.update` routes, and include `@csrf` (the unsubscribe route ignores it, but the preference route checks it).

## Subscription topics

Topics let people opt out of one kind of email, such as webinar invitations, without leaving your list. There are two independent mechanisms.

### Topic records

`MarketingSubscriptionTopic` has a `name`, a unique `slug`, a `description`, `is_default` (default `true`), and a `sort_order`. Each address's choice is stored in `MarketingContactTopic` (`email`, `topic_id`, `is_subscribed`, `unsubscribed_at`, `contact_id`).

```php
use Odden\Marketing\Models\MarketingSubscriptionTopic;

$webinars = MarketingSubscriptionTopic::create([
    'name' => 'Webinars',
    'slug' => 'webinars',
    'description' => 'Invitations to live sessions',
    'is_default' => false,
    'sort_order' => 2,
]);

MarketingSubscriptionTopic::setSubscription('pat@example.com', $webinars->id, true, $contact->id);

MarketingSubscriptionTopic::isSubscribed('pat@example.com', 'webinars'); // true
```

`isSubscribed(string $email, int|string $topicIdOrSlug): bool` returns the address's saved choice, or the topic's `is_default` if there isn't one. An unknown topic returns `true`.

To scope a campaign to a topic, set its `topic_id`. Dispatch then skips anyone not subscribed to that topic:

```php
$campaign->update(['topic_id' => $webinars->id]);
```

### Contact topic slugs

The contact's own `marketing_topics` column holds an array of topic slugs. `ContactPreferences::isSubscribedToTopic(Contact $contact, string $topic)` returns `true` if the slug is in the array, or if the column is `null` (a contact who never chose is subscribed to everything).

Set a campaign's `topic` (a string, up to 50 characters) to make dispatch check it:

```php
$campaign->update(['topic' => 'product_updates']);
```

The preference center writes both mechanisms, so they agree for contacts who have used it. If you set `marketing_topics` or `MarketingContactTopic` rows yourself, keep them consistent, or scope campaigns with only one of `topic_id` and `topic`.

## Double opt-in

Double opt-in asks a new subscriber to confirm their address by clicking a link. Odden provides the confirmation endpoint; sending the email is up to you.

```php
use Illuminate\Support\Facades\Mail;
use Odden\Marketing\Support\ContactPreferences;

ContactPreferences::preferenceCenterUrl($contact); // ensures the contact has a marketing_verification_token

$confirmUrl = route('odden.marketing.confirm', $contact->marketing_verification_token);

Mail::raw("Confirm your subscription: {$confirmUrl}", function ($message) use ($contact): void {
    $message->to($contact->email)->subject('Please confirm your email');
});
```

`GET /marketing/confirm/{token}` finds the contact by `marketing_verification_token` (`404` if there's none), sets `marketing_email_verified_at` if it's empty, and shows the `confirmed` page. The first confirmation also applies a `PropertyMatch` [lead scoring](lead-scoring.md) event.

Things to know:

- The confirmation token is the same token the preference center uses, so anyone with a preference link can also confirm the address.
- Campaign dispatch doesn't check `marketing_email_verified_at`. To mail only confirmed contacts, pass them in yourself:

```php
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\DispatchCampaignAction;

$confirmed = $list->contacts()->whereNotNull('marketing_email_verified_at')->get();

app(DispatchCampaignAction::class)->execute($campaign, $confirmed);
```

## Merging contacts

When Core [merges two contacts](../core/duplicates-and-merging.md#what-each-module-moves), Marketing moves the secondary contact's subscription and topic rows to the primary contact. These rows are keyed by email, so they keep applying to the secondary's address.

A merge never resubscribes anyone:

- If the secondary is unsubscribed, the primary's address is unsubscribed too. Its `unsubscribed_at` is the secondary's. A primary that is already unsubscribed or bounced is left as it is.
- If the secondary opted out of a topic, the primary's address is opted out of that topic too.
- A primary that is unsubscribed stays unsubscribed, even if the secondary was subscribed.
- Bounces belong to an address, so a bounced secondary address doesn't change the primary's status.

The `marketingSubscription` relation is a `HasOne`. If both contacts had a subscription row with different addresses, the primary now has two, and the relation returns one of them. Use `MarketingSubscription::isSuppressed($contact->email)` to check whether you can mail a contact.

When both contacts received the same campaign, the recipient that unsubscribed is kept, otherwise the most engaged one. The other recipient row is detached from the contact rather than deleted, so the unsubscribe and tracking links in the email it was sent keep working. Unsubscribing through that link suppresses the address it was sent to.

## What the transactional API checks

The [transactional API](transactional-email.md) sends to whatever address you give it. It doesn't check subscription status, topics, or the suppression list, because transactional email (receipts, password resets) usually has to go out regardless. Check `MarketingSubscription::isSuppressed()` yourself before calling it for anything promotional.
