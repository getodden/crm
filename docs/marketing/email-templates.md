---
title: Email templates
description: Build reusable email templates from mail builder slots, keep revisions and translations, personalize them with merge tags and smart content, and add dynamic images and AMP.
---

Campaigns, workflow emails, and the transactional API all send an `Odden\Marketing\Models\MarketingTemplate`. A template is either a list of [mail builder](https://github.com/getodden/mail) slots, which the package compiles to responsive HTML for you, or raw HTML you supply.

## Creating a template

Build a template from slots, the content blocks of `getodden/mail`:

```php
use Odden\Marketing\Models\MarketingTemplate;

$template = MarketingTemplate::create([
    'name' => 'March newsletter',
    'subject' => 'What shipped in March',
    'preview_text' => 'Three new features and a webinar invite',
    'slots' => [
        ['type' => 'header', 'data' => ['brand_name' => 'Acme']],
        ['type' => 'body_text', 'data' => ['content' => '<p>Hi {{contact.first_name}}, here is what is new.</p>']],
        ['type' => 'button', 'data' => ['text' => 'Read the changelog', 'url' => 'https://acme.test/changelog']],
        ['type' => 'footer', 'data' => ['company_name' => 'Acme Inc.', 'address' => '1 Main St, Springfield']],
    ],
]);

$template->slug;      // 'march-newsletter'
$template->body_html; // the compiled, CSS-inlined HTML
$template->body_text; // the plain-text version
```

Or give it HTML directly:

```php
$template = MarketingTemplate::create([
    'name' => 'Plain welcome',
    'subject' => 'Welcome aboard',
    'body_html' => '<p>Hi {{contact.first_name}}, welcome!</p><p><a href="{{unsubscribe_url}}">Unsubscribe</a></p>',
]);
```

Every time a template is saved:

- If `slots` is set, `body_html` is recompiled from the slots, `subject`, `preview_text`, and `theme`.
- `body_text` is generated from the slots, or from `body_html` when there are no slots, when it's empty. It is regenerated when the slots or HTML change, as long as it still equals the text generated from the previous content; a `body_text` you edited by hand is left alone. The same applies to `body_text_variant_b`.
- If `slug` is empty, it's set to `Str::slug($name)`. Slugs aren't unique in the database.
- A [revision](#revisions) is recorded.

`body_html` can't be null, so a template needs either `slots` or `body_html`.

### Attributes

| Attribute | Default | Purpose |
| :--- | :--- | :--- |
| `name` | | Internal name |
| `slug` | from `name` | Identifier for the [transactional API](transactional-email.md) |
| `subject`, `preview_text` | | Subject line and inbox preview text |
| `slots` | `null` | Mail builder slots |
| `theme` | `null` | Theme overrides passed to the mail builder compiler |
| `body_html`, `body_text` | | Compiled (or hand-written) HTML and plain text |
| `category` | `general` | Free-form grouping |
| `subject_variant_b`, `preview_text_variant_b`, `slots_variant_b` | `null` | [Variant B](#variant-b) |
| `body_html_variant_b`, `body_text_variant_b` | `null` | Compiled variant B |
| `ab_winner_variant`, `ab_completed_at` | `null` | Set by `EvaluateTemplateAbTestsAction` |
| `ab_split_percentage` | `50` | Stored only; nothing reads it |

## Slots

A slot is an array with a `type`, a `data` array, and an optional `visibility` rule. The types come from `Odden\MailBuilder\Enums\SlotType`:

`header`, `hero`, `body_text`, `button`, `two_column`, `three_column`, `four_column`, `asymmetric_columns`, `features`, `testimonial`, `stat_box`, `divider`, `labeled_divider`, `social_links`, `footer`, `html`, `image_banner`, `video_card`, `pricing_grid`, `rating_bar`, `countdown_timer`, `accordion`, `dynamic_feed`, `rss_feed`, `order_receipt`, `product_catalog`, `coupon_code`, `app_badges`, and `data_table`.

Each type's `data` keys are defined by the mail builder package. A few common ones:

| Type | Keys |
| :--- | :--- |
| `header` | `brand_name`, `logo_url`, `logo_height`, `tagline`, `bg_color`, `text_color` |
| `body_text` | `content` (HTML), `align`, `bg_color` |
| `button` | `text`, `url`, `style`, `align`, `bg_color`, `text_color` |
| `footer` | `company_name`, `address`, `unsubscribe_url`, `preferences_url`, `notice` |

The `footer` slot links to `{{unsubscribe_url}}` unless you set `unsubscribe_url`, so campaigns fill it with each recipient's unsubscribe link.

The `theme` array overrides the mail builder's `mail-builder.defaults`, such as `primary_color`, `font_family`, `background_color`, and `container_width`:

```php
$template->update(['theme' => ['primary_color' => '#16a34a', 'font_family' => 'Inter']]);
```

### Conditional slots

A slot with a `visibility` rule is only included when the rule matches the recipient:

```php
[
    'type' => 'body_text',
    'data' => ['content' => '<p>Thanks for being a customer.</p>'],
    'visibility' => ['field' => 'contact.lifecycle_stage', 'operator' => 'equals', 'value' => 'customer'],
]
```

Operators are `equals`, `not_equals`, `contains`, `is_empty`, and `is_not_empty`. The `field` is a dot path into the recipient context:

- In campaigns, the context is `contact` (the contact's attributes) and `company` (their first company's attributes).
- In the transactional API, it's the request's `context` object.

Rules aren't evaluated when `body_html` is compiled on save, so the stored HTML contains every slot.

### Saved blocks

`MarketingSavedBlock` stores a single slot for reuse in an editor: `name`, `category` (default `general`), `slot_type`, `slot_data` (array), and `created_by`. The package only stores them; inserting one into a template is up to your editor.

```php
use Odden\Marketing\Models\MarketingSavedBlock;

MarketingSavedBlock::create([
    'name' => 'Standard footer',
    'slot_type' => 'footer',
    'slot_data' => ['company_name' => 'Acme Inc.', 'address' => '1 Main St, Springfield'],
]);
```

## Variant B

A template can hold its own A/B variant. Set `subject_variant_b`, `preview_text_variant_b`, and `slots_variant_b`. On save, `slots_variant_b` is compiled to `body_html_variant_b`; if you set only `subject_variant_b`, the variant A slots are compiled again with the B subject.

```php
$template->update(['subject_variant_b' => 'Three things we shipped']);

$template->hasAbTest();               // true
$template->getVariantSubject('B');    // 'Three things we shipped'
$template->getVariantHtml('B');       // body_html_variant_b, or body_html if empty
$template->getVariantText('B');
```

[A/B campaigns](campaigns.md#ab-testing) use these when the campaign has no `variant_b_template_id`. The [transactional API](transactional-email.md) uses them when you pass `"variant": "B"`, and defaults to `ab_winner_variant`.

`EvaluateTemplateAbTestsAction` compares the variants across every campaign that uses the template and stores the winner:

```php
use Odden\Marketing\Actions\EvaluateTemplateAbTestsAction;

$result = app(EvaluateTemplateAbTestsAction::class)->execute($template, 'click_rate');

$result['winner'];     // 'A' or 'B', saved to ab_winner_variant
$result['confidence']; // e.g. 'Low (Sample < 100)'
```

The second argument is `click_rate` (the default) or `open_rate`. The result also has `variant_a` and `variant_b` (`sent`, `opens`, `clicks`, `open_rate`, `click_rate`) and `sample_size`. `confidence` is a rough label from sample size, not a statistical test; use [`AbTestSignificanceCalculator`](campaigns.md#significance) for that.

## Revisions

Every save that leaves the template with slots or HTML records a `MarketingTemplateRevision` with the next `version_number`. It copies the subject, preview text, slots, theme, HTML, and text (both variants), plus `notes` and `created_by` (the authenticated user's id).

```php
$template->revisions;  // newest first

$template->createRevision('Approved by legal');  // an explicit snapshot with notes

$revision = $template->revisions()->where('version_number', 1)->first();
$template->restoreRevision($revision);
```

`restoreRevision(int|MarketingTemplateRevision $revision): bool` takes a revision model or a revision **id** (not a version number). Restoring saves the template, which records another revision.

## Translations

`MarketingTemplateTranslation` holds per-locale content: `locale`, `subject`, `subject_variant_b`, `preview_text`, `preview_text_variant_b`, `body_html`, `body_text`, and `slots`.

```php
$template->translations()->create([
    'locale' => 'fr',
    'subject' => 'Les nouveautés de mars',
]);

$template->getLocalizedSubject('fr');       // 'Les nouveautés de mars'
$template->getLocalizedSubject('de');       // falls back to the template subject
$template->getLocalizedSubject('fr', 'B');  // subject_variant_b, or subject
```

`getLocalizedSubject()` is the only place translations are read. Campaigns and the transactional API always send the default content, so to send a translation, read its fields yourself.

## Merge tags

Merge tags are placeholders like `{{contact.first_name}}`. How they're filled depends on how the email is sent.

### In the mail builder

The package registers four groups with the mail builder's `MergeTagRegistry`, with sample values for previews:

| Group | Tags |
| :--- | :--- |
| Contact | `{{contact.first_name}}`, `{{contact.last_name}}`, `{{contact.full_name}}`, `{{contact.email}}`, `{{contact.job_title}}`, `{{contact.phone}}`, `{{contact.lifecycle_stage}}` |
| Company | `{{company.name}}`, `{{company.domain}}`, `{{company.industry}}` |
| Event | `{{event.<id>.rsvp_token}}`, the contact's signed RSVP token for event `<id>` (see [AMP](#amp-for-email)) |
| Sender / Owner | `{{sender.name}}`, `{{sender.email}}` |

The registry is what editors show as the tag list. Register your own groups the same way:

```php
use Odden\MailBuilder\MailBuilder;

MailBuilder::mergeTags()->register('Order', [
    '{{order.number}}' => 'Order number',
], ['order' => ['number' => 'A-1001']]);
```

The mail builder's interpolator, used by the [transactional API](transactional-email.md) and [previews](#previewing-a-template), supports:

- Dot paths into the data, nested (`['order' => ['number' => …]]`) or flat (`['order.number' => …]`)
- Spaces inside the braces: `{{ contact.first_name }}`
- Filters: `upper`, `lower`, `capitalize`, `title`, `trim`, `date:"M j, Y"`, `currency:"$"`, `number:2`, `pluralize:"item","items"`, `truncate:50`, and `default:"there"`
- Conditionals: `{% if order.gift %}…{% else %}…{% endif %}`

```php
MailBuilder::interpolate('Hi {{ contact.first_name | default:"there" }}', []); // 'Hi there'
```

A tag with no value is left in the email as written.

### In campaigns

Campaigns and workflow emails use the same interpolator, so every tag in the registry works, with spaces, filters and conditionals: the contact, company and sender tags above, plus `{{unsubscribe_url}}` and (in campaigns) `{{campaign.subject}}` and `{{campaign.name}}`. See [The compiled message](campaigns.md#the-compiled-message) for the values and fallbacks, such as `there` for a missing first name.

Values are HTML-escaped with `e()` before they're interpolated, so contact or company data containing `<` or `&` can't add markup to the email. A tag you registered yourself needs a value in the context to be filled; unknown tags are left as written.

## Smart content

Smart content shows different copy to different contacts. It works in campaign and workflow emails (it's applied by `EvaluateSmartContentBlocksAction`), not in the transactional API.

**Blocks.** Adjacent `[smart …]…[/smart]` blocks form a group. The first block whose rules all match is shown; otherwise the `[smart default]` block, or nothing:

```html
[smart tier="tier_1"]<p>Your account team will be in touch.</p>[/smart]
[smart min_score="100"]<p>Book a call with sales.</p>[/smart]
[smart default]<p>Read our getting started guide.</p>[/smart]
```

**Inline tokens.** `{{smart:rule=value?shown if true:shown if false}}`:

```html
<p>{{smart:stage=customer?Upgrade to Enterprise:Start your free trial}}</p>
```

Rules:

| Rule | Matches when |
| :--- | :--- |
| `tier`, `account_tier` | The contact's first company's `account_tier` equals the value |
| `stage`, `lifecycle_stage` | The contact's lifecycle stage value equals the value |
| `status`, `lead_status` | The contact's lead status value equals the value |
| `industry` | The company's `industry` equals the value |
| `surge`, `intent_surge` | The company's `intent_surge` equals the boolean value |
| `min_score`, `max_score` | The contact's `lead_score` is at least, or at most, the value |
| `has_company` | Whether the contact has a company equals the boolean value |
| anything else | The contact's custom property, or attribute, with that name equals the value |

Comparisons ignore case. With no contact, blocks fall back to the default and inline tokens to the false branch.

```php
use Odden\Marketing\Actions\EvaluateSmartContentBlocksAction;

$html = app(EvaluateSmartContentBlocksAction::class)->execute(
    '[smart min_score="100"]Hot[/smart][smart default]Cold[/smart]',
    $contact,
);
```

The action also accepts a Blade-style form, where a single `@endsmart` closes the whole group and the content of each branch is trimmed:

```
@smart(stage="customer") Thank you for being a customer! @smart(default) Learn more about us. @endsmart
```

It is the same as writing the `[smart]` blocks, so `@smart(default)` is the fallback branch.

## Previewing a template

`ContactPersonaPreviewService::preview()` compiles a template's slots for a real contact or a sample persona, with visibility rules and merge tags applied:

```php
use Odden\Marketing\Services\ContactPersonaPreviewService;

$preview = ContactPersonaPreviewService::preview($template, 'trial_user');

$preview['compiled_html'];
$preview['visible_slots_count'];
$preview['hidden_slots_count'];
```

The second argument is a `Contact`, a contact id, or one of the built-in personas: `vip_customer`, `trial_user`, or `churn_risk`. The result also has `persona_label`, `context`, `plain_text`, and `total_slots`.

For a real contact, the context uses flat keys (`contact.first_name`, `company.name`, …), and company fields are only included if the contact's `companies` relation is already loaded. Only slots are previewed: a template without slots compiles to an empty layout.

## Dynamic images

Two routes return SVG images generated from query parameters, for use in an `<img>` tag. They're rendered when the email is opened, so the image is current each time.

**Countdown timer.** `GET /marketing/images/countdown-timer.svg` (`odden.marketing.images.countdown-timer`)

| Parameter | Default |
| :--- | :--- |
| `until` | `now + 3 days`; any date Carbon can parse. Unparseable values count down two days from now |
| `label` | `FLASH SALE ENDS IN` |
| `color` | `#2563EB` (label color) |
| `bg` | `#0F172A` |
| `text` | `#FFFFFF` (digit color) |

After `until` passes, every digit shows `00`.

**Badge.** `GET /marketing/images/badge.svg` (`odden.marketing.images.badge`) draws an attendee badge with `name` (default `Valued Guest`), `company` (`Acme Corporation`), `role` (`VIP Attendee`), and `color` (`#4F46E5`). Its footer reads "OFFICIAL ODDEN SUMMIT ACCESS PASS" and can't be changed.

```php
$src = route('odden.marketing.images.countdown-timer', [
    'until' => '2026-12-01 17:00:00',
    'label' => 'SALE ENDS IN',
]);
```

```html
<img src="{{ $src }}" width="560" height="130" alt="Sale ends December 1">
```

Both are public and send no-cache headers. Many email clients, Gmail among them, don't display SVG images, so check your audience's clients before relying on them.

## AMP for email

The mail builder can compile slots to AMP for Email markup with `MailBuilder::amp($slots, $options)`. Neither campaigns nor the transactional API attach an AMP part to the messages they build, so adding one is up to you.

Two public endpoints in the `api` group accept submissions from AMP forms. Both are CSRF exempt and limited by `throttle:odden-public`.

They follow the [AMP for Email CORS rules](https://amp.dev/documentation/guides-and-tutorials/learn/cors-in-email). The request's `Origin` must be in `odden-marketing.amp.allowed_origins`; any other origin, or none, gets a `403` with no CORS headers. An allowed request gets `Access-Control-Allow-Origin` set to its origin, `AMP-Email-Allow-Sender` set to the request's `AMP-Email-Sender` header, and `Access-Control-Expose-Headers: AMP-Email-Allow-Sender`. Credentials aren't allowed. The default origins are the AMP email clients':

```php
// config/odden-marketing.php
'amp' => [
    'allowed_origins' => ['https://mail.google.com', 'https://outlook.live.com', 'https://mail.yahoo.com', 'https://mail.aol.com'],
],
```

Set `ODDEN_MARKETING_AMP_ALLOWED_ORIGINS` to a comma-separated list to replace them. The package tells Laravel's global CORS middleware to skip these two routes, so your `config/cors.php` doesn't override their headers.

**Feedback.** `POST /api/marketing/amp/feedback` (`odden.marketing.amp.feedback`)

| Field | Rules |
| :--- | :--- |
| `score` | required, integer 0–10 |
| `token` | optional; the token of an existing `NpsResponse` to update |
| `feedback` | optional, up to 2000 characters |
| `email` | optional, email |

If `token` matches an NPS response, its `score`, `feedback`, and `responded_at` are saved. Otherwise nothing is stored.

```json
{"status": "success", "message": "Thank you! Your feedback has been recorded.", "score": 9}
```

**RSVP.** `POST /api/marketing/amp/rsvp` (`odden.marketing.amp.rsvp`)

| Field | Rules |
| :--- | :--- |
| `event_slug` | required |
| `token` | required; the recipient's signed RSVP token for this event |
| `status` | `attending` (default), `declined`, or `tentative` |

The contact comes only from `token`, never from a submitted email, and the endpoint never creates contacts. Campaign and workflow emails fill the token for each recipient with the `{{event.<id>.rsvp_token}}` merge tag, where `<id>` is the event's ID (for example `{{event.12.rsvp_token}}`). Put it in a hidden field of the AMP form:

```html
<form method="post" action-xhr="https://crm.example.com/api/marketing/amp/rsvp" target="_top">
    <input type="hidden" name="event_slug" value="spring-summit">
    <input type="hidden" name="token" value="{{event.12.rsvp_token}}">
    <select name="status">
        <option value="attending">Yes</option>
        <option value="tentative">Maybe</option>
        <option value="declined">No</option>
    </select>
    <input type="submit" value="RSVP">
</form>
```

The tag only fills for events that exist and a recipient who is a contact; a tag for an unknown event is left as written. Outside a campaign you can issue the same token yourself:

```php
$token = $event->rsvpTokenFor($contact); // "{contact id}.{HMAC-SHA256 keyed with app.key}"
```

A token is valid only for the event it was issued for. With a valid token, the contact's registration for the event is created or updated:

```json
{"status": "success", "message": "Your RSVP has been saved successfully.", "event": "spring-summit", "rsvp_status": "attending"}
```

A missing `token` returns a `422`. An unknown event, or a token that's wrong or was issued for another event, returns a `403`:

```json
{"status": "error", "message": "This RSVP link is invalid or has expired."}
```

See [Events and gated assets](events-and-assets.md) for events.

## Other renderers

`Odden\Marketing\Services\EmailBlockRenderer` is a simpler HTML renderer, separate from the mail builder, used by the Filament plugin's preset picker. `render(array $blocks)` accepts flat blocks of type `hero`, `columns`, `features`, `testimonial`, `cta`, `footer`, or text, and `renderPreset('product_launch')` renders a built-in preset. New templates should use slots.
