---
title: Forms and landing pages
description: Capture leads with hosted forms, an embeddable form script, a headless JSON schema and API endpoint, progressive profiling, and hosted landing pages.
---

A marketing form is an `Odden\Marketing\Models\MarketingForm` record with a list of fields. You can serve the same form four ways: as a hosted page, through an embed script on another site, from your own front end using its JSON schema, or inside a hosted landing page. Every submission goes through `ProcessFormSubmissionAction`, which finds or creates the contact, scores the lead, and enrolls the contact in workflows.

All routes on this page are registered by the package. Their paths depend on the `odden-marketing.routes` prefixes (see [public routes](../configuration.md#public-routes)); the paths below use the defaults (no prefix for web routes, `api/marketing` for API routes). Route names never change, so build links with `route()` or the model helpers.

## Creating a form

```php
use Odden\Marketing\Models\MarketingForm;

$form = MarketingForm::create([
    'title' => 'Request a Demo',
    'description' => 'Tell us about your team.',
    'fields_schema' => [
        ['name' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'Work email', 'type' => 'email', 'required' => true],
        ['name' => 'company', 'label' => 'Company', 'type' => 'text'],
        ['name' => 'team_size', 'label' => 'Team size', 'type' => 'select', 'options' => ['1-10', '11-50', '51+']],
    ],
    'submit_button_text' => 'Book my demo',
    'success_message' => 'Thanks! We will be in touch.',
]);

$form->slug;              // "request-a-demo"
$form->getPublicUrl();    // "https://your-app.test/forms/request-a-demo"
$form->getApiEndpoint();  // "https://your-app.test/api/marketing/forms/request-a-demo"
```

| Attribute | Notes |
| --- | --- |
| `title` | Required. |
| `slug` | Unique. Generated from `title` with `Str::slug()` when left empty. |
| `description` | Shown under the title. |
| `fields_schema` | Required. Array of field definitions (see below). |
| `submit_button_text` | Defaults to `Submit` in the database. |
| `success_message` | Shown after a submission. Each renderer has its own fallback text. |
| `redirect_url` | When set, the visitor is sent here after submitting instead of seeing the success message. |
| `is_active` | Defaults to `true`. Inactive forms return 404 on every route. |
| `progressive_profiling_enabled`, `progressive_fields` | See [progressive profiling](#progressive-profiling). |
| `submissions_count` | Incremented on every submission. |

### Field definitions

Each entry in `fields_schema` is an array with these keys:

- `name` (required): the input name and the key the value is stored under.
- `label`: display label. The hosted page falls back to the name with underscores replaced by spaces.
- `type`: `text`, `email`, `textarea`, `select`, or any other HTML input type such as `tel` or `number`. Defaults to `text`.
- `required`: `true` to make the field required.
- `options`: for `select` fields. The hosted page and landing pages expect a list of strings. The embed script also accepts `['value' => ..., 'label' => ...]` objects.
- `placeholder`: used by the embed script only.

Validation is built from the fields: each field becomes `required` or `nullable`, and fields of type `email` also get the `email` rule. Nothing else is validated. The rules come from the fields the visitor was shown: the base fields, or the resolved progressive fields when a valid signed contact link was sent.

### What happens on submission

`Odden\Marketing\Actions\ProcessFormSubmissionAction` handles every submission. Five field names are mapped to the contact record: `email`, `first_name`, `last_name`, `phone`, and `company`. When the submission carries an `email` and no verified contact was passed in:

1. The email is trimmed and lowercased, and the contact with that email is loaded or created (new contacts get `lead_status` `new` and `lifecycle_stage` `lead`). An existing contact's `first_name`, `last_name`, and `phone` are filled in only if they're empty.
2. If `sms_consent` is truthy, `sms_consent` and `sms_consent_at` are set on the contact.
3. If `visitor_token` is present, anonymous web sessions with that token are [stitched](web-tracking.md#identity-stitching) to the contact.
4. If `company` is present, the company with that exact name is loaded or created and associated with the contact. Otherwise the contact is matched to a company by email domain (see [account intent](lead-scoring.md#account-intent)).
5. A task titled `Form Submission: {form title}` is logged on the contact's timeline.
6. The `form_submission` [lead scoring](lead-scoring.md) event is applied (15 points by default).

Then, for any resolved contact (including a [verified](#progressive-profiling) one):

- Submitted keys that are declared as a field `name` in the form's `fields_schema` or `progressive_fields` are saved to the contact's [custom properties](../core/custom-properties.md). The five mapped fields above, `sms_consent`, `visitor_token`, `contact_id`, `_token` and the `utm_*` keys are never saved as properties, and keys the form doesn't declare are kept only in the submission's `form_data`.
- Only a verified contact (one passed in as `$contact`, which the submit endpoints do for a valid signed `contact` token) can change a property that already has a value. When the contact was matched by email or just created, properties are only filled in where they're empty, so someone who knows a contact's email can't overwrite their data.
- A `FormSubmission` row is stored with the raw `form_data`, `ip_address`, `user_agent`, and `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content` taken from the submitted data.
- The form's `submissions_count` is incremented.
- The contact is enrolled in active workflows with the `form_submitted` trigger whose `trigger_config.form_id` matches this form or is unset (see [workflows](workflows.md#triggers)).

A submission without an email and without a verified contact is still stored, with a `null` `contact_id`.

Submissions are available as `$form->submissions` (newest first) and `$contact->formSubmissions`, a relation the package adds to `Contact`.

You can call the action yourself, for example to import submissions from another tool:

```php
use Odden\Marketing\Actions\ProcessFormSubmissionAction;

$submission = app(ProcessFormSubmissionAction::class)->execute(
    form: $form,
    data: [
        'email' => 'grace@example.com',
        'first_name' => 'Grace',
        'team_size' => '51+',
        'utm_source' => 'webinar',
    ],
    ipAddress: '203.0.113.10',
);

$submission->contact->email; // "grace@example.com"
```

The full signature is `execute(MarketingForm $form, array $data, ?string $ipAddress = null, ?string $userAgent = null, ?Contact $contact = null): FormSubmission`. Pass `$contact` only when you've verified who is submitting. It skips the email lookup and the steps listed above that depend on it, including scoring, and lets the submission overwrite the contact's existing property values. In the example above, `team_size` is saved as a property only if the form declares a `team_size` field.

## Hosted forms

| Method | URI | Route name | Notes |
| --- | --- | --- | --- |
| `GET` | `/forms/{slug}` | `odden.marketing.forms.show` | Renders `odden-marketing::forms.show`. |
| `POST` | `/forms/{slug}` | `odden.marketing.forms.submit` | `web` middleware, so CSRF protected. Rate limited by `odden-public`. |

The hosted page posts back to itself. After a successful submission it redirects to `redirect_url` if set, or renders `odden-marketing::forms.success` with the `success_message`. If the request expects JSON (an `Accept: application/json` header), it returns the same JSON as the [API endpoint](#form-api-endpoint) instead. Validation errors redirect back with the usual error bag.

To restyle the pages, override the views by creating `show.blade.php` and `success.blade.php` in `resources/views/vendor/odden-marketing/forms/` (the package has no view publish tag). The default views load Tailwind from its CDN.

## Embedding a form on another site

The embed script renders a form from its JSON schema and submits it to the API endpoint, so it works on any domain.

| Method | URI | Route name |
| --- | --- | --- |
| `GET` | `/marketing/forms/embed.js` | `odden.marketing.forms.embed-script` |
| `GET` | `/marketing/forms/{slug}/embed.js` | `odden.marketing.forms.slug-embed-script` |

The generic script renders every element with a `data-odden-form` attribute. The slug-specific script renders only the element with `data-odden-form="{slug}"` or `id="odden-form-{slug}"`.

```html
<div data-odden-form="request-a-demo"></div>
<script src="https://your-app.test/marketing/forms/embed.js" async></script>
```

Two optional attributes on the container turn the form into a popup:

- `data-odden-display`: `inline` (default, replaces the container's content), `modal` (centered overlay), or `slide-in` (bottom-right card).
- `data-odden-trigger`, for `modal` and `slide-in` only: `immediate` (default), `exit-intent` (pointer leaves through the top of the window), `scroll-50` (half the page scrolled), or `delay-{seconds}`, for example `delay-10`.

```html
<div data-odden-form="request-a-demo" data-odden-display="modal" data-odden-trigger="exit-intent"></div>
```

Closing a popup sets a `sessionStorage` flag, so it isn't shown again in that browser session. The script is served with `Cache-Control: public, max-age=3600`.

On submit the script posts the field values as JSON together with the visitor's id as `visitor_token`. It uses the same id as the [tracking script](web-tracking.md#visitor-tokens-and-cross-domain-tracking) (`_odden_vid` in `localStorage` and a first-party cookie on your site, created if missing), so the contact the submission creates or matches is [stitched](web-tracking.md#identity-stitching) to the pages the visitor viewed before, even when your site is on another domain.

## Headless forms

Use the schema endpoint when you render the form yourself (Next.js, Remix, Webflow, a mobile app).

| Method | URI | Route name |
| --- | --- | --- |
| `GET` | `/marketing/forms/{slug}/schema.json` | `odden.marketing.forms.schema` |

```json
{
    "id": 1,
    "title": "Request a Demo",
    "slug": "request-a-demo",
    "description": "Tell us about your team.",
    "submit_button_text": "Book my demo",
    "action_url": "https://your-app.test/api/marketing/forms/request-a-demo",
    "fields": [
        {"name": "first_name", "label": "First name", "type": "text", "required": true},
        {"name": "email", "label": "Work email", "type": "email", "required": true},
        {"name": "company", "label": "Company", "type": "text"},
        {"name": "team_size", "label": "Team size", "type": "select", "options": ["1-10", "11-50", "51+"]}
    ],
    "progressive_profiling": false
}
```

`fields` is the form's `fields_schema`, or the [progressive](#progressive-profiling) field list when a valid `contact` token is passed as a query parameter. Post the values to `action_url`.

## Form API endpoint

| Method | URI | Route name | Auth |
| --- | --- | --- | --- |
| `POST` | `/api/marketing/forms/{slug}` | `odden.marketing.forms.api-submit` | Public. CSRF exempt, rate limited by `odden-public`. |

This endpoint needs no API token, because browsers call it directly. Send the field values as JSON or form data, with `Accept: application/json`:

```bash
curl -X POST https://your-app.test/api/marketing/forms/request-a-demo \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"first_name": "Ada", "email": "ada@example.com", "company": "Analytical Engines Ltd", "utm_source": "linkedin", "utm_campaign": "q3-launch", "visitor_token": "..."}'
```

```json
{
    "success": true,
    "message": "Thanks! We will be in touch.",
    "redirect_url": null,
    "submission_id": 42
}
```

`message` falls back to `Thank you for your submission!` when the form has no `success_message`. Validation failures return Laravel's standard `422` JSON error response, and inactive or unknown forms return `404`. The controller returns JSON when the request expects JSON or its path matches `api/*`; if you change the API prefix, send the `Accept` header so you don't get a redirect.

The `odden-public` limit is per IP address and defaults to 30 requests per minute (see [rate limits](../configuration.md#rate-limits)).

## Progressive profiling

With progressive profiling, a returning contact isn't asked again for things you already know. Each base field the contact has a value for is replaced by the next unanswered question from `progressive_fields`.

```php
$form = MarketingForm::create([
    'title' => 'Product Updates',
    'fields_schema' => [
        ['name' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true],
        ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true],
    ],
    'progressive_profiling_enabled' => true,
    'progressive_fields' => [
        ['name' => 'job_function', 'label' => 'Job function', 'type' => 'text', 'required' => true],
        ['name' => 'budget', 'label' => 'Annual budget', 'type' => 'select', 'options' => ['< $10k', '$10k+']],
    ],
]);

$fields = $form->resolveFieldsForContact($contact);
// For a contact with a first name and email: the job_function and budget fields,
// each with 'is_progressive' => true.
```

`resolveFieldsForContact(?Contact $contact): array` walks the base fields in order:

- A field the contact doesn't have a value for is kept.
- A known field is replaced by the next progressive field, marked `'is_progressive' => true`. Once the queue is empty, further known fields are dropped.
- If every field was dropped, the first three progressive fields are returned.

The base fields are returned unchanged when profiling is disabled, `progressive_fields` is empty, or there's no contact. `isFieldKnownByContact(Contact $contact, string $fieldName): bool` decides what counts as known: `email`, `first_name`, `last_name`, and `phone` check the contact's columns, `company` checks for an associated company, and any other name checks the custom property of that name.

### Signed personalized links

A form recognizes a contact only through a signed token, never through a bare id or email in the URL. To send a contact a personalized link, for example in an email, pass the contact to `getPublicUrl()`:

```php
$url = $form->getPublicUrl($contact);
// https://your-app.test/forms/product-updates?contact=17.3f9c...
```

The hosted page greets the contact ("Welcome back, Sarah!"), shows the progressive questions with a "Smart Question" badge, and carries the token in a hidden `contact` field. The schema endpoint accepts the same `contact` query parameter, and the submit endpoints accept it as a `contact` input. When the token is valid, the submission is attached to that contact: progressive answers are saved as custom properties (replacing existing values), but none of the email-based steps run, so no lead score is added and no timeline task is logged.

The token comes from `Odden\Marketing\Support\ContactToken`:

```php
use Odden\Marketing\Support\ContactToken;

$scope = ContactToken::forForm($form->id);       // "form:{id}"
$token = ContactToken::make($contact, $scope);   // "{contact id}.{64-char HMAC}"

ContactToken::resolve($token, $scope);           // the Contact
ContactToken::resolve($token, ContactToken::forForm($otherForm->id)); // null
ContactToken::resolve((string) $contact->id, $scope);                 // null
```

The signature is an HMAC-SHA256 of the scope and contact id keyed with `app.key`, so a token for one form doesn't work on another. Tokens don't expire, and rotating `APP_KEY` invalidates all of them. `resolve()` returns `null` for anything malformed, tampered, or pointing to a deleted contact.

A visitor without a valid token sees the base fields, and is validated against the base fields, whatever email they type. Progressive fields (required or not) therefore never cause a `422` for someone who wasn't shown them.

## Landing pages

`Odden\Marketing\Models\LandingPage` is a hosted page with a headline, HTML body, and an optional form.

```php
use Odden\Marketing\Models\LandingPage;

$page = LandingPage::create([
    'title' => 'Q3 Launch',
    'slug' => 'q3-launch',
    'headline' => 'Ship faster with Odden',
    'body_content' => '<p>Join the beta.</p>',
    'form_id' => $form->id,
    'is_published' => true,
    'published_at' => now(),
]);

$page->getPublicUrl();     // "https://your-app.test/p/q3-launch"
$page->getEmbedSnippet();  // '<iframe src="https://your-app.test/p/q3-launch" width="100%" height="600" frameborder="0" style="border:none;"></iframe>'
$page->conversion_rate;    // submissions_count / views_count * 100, rounded to 2 decimals
```

Other attributes: `subheadline`, `meta_title`, `meta_description`, `og_image_url`, `created_by_id` (`creator` relation to your user model), and the counters `views_count` and `submissions_count`. `slug` is required and isn't generated for you. Pages default to unpublished.

`body_content` is printed unescaped, so only store HTML you trust.

| Method | URI | Route name | Notes |
| --- | --- | --- | --- |
| `GET` | `/p/{slug}` | `odden.marketing.landing-pages.show` | 404 unless `is_published`. |
| `POST` | `/p/{slug}/submit` | `odden.marketing.landing-pages.submit` | CSRF protected, rate limited by `odden-public`. |

Each view increments `views_count` and records a [page view](web-tracking.md#recording-visits-from-php) with the `utm_source`, `utm_medium` and `utm_campaign` query parameters, using the visitor's `odden_vid` cookie if there is one. The page also loads the [tracking script](web-tracking.md), whose pageview sets that cookie to the visitor's id (see [visitor tokens](web-tracking.md#visitor-tokens-and-cross-domain-tracking)), so the submission is stitched to the pages viewed before it.

A submission is passed to `ProcessFormSubmissionAction` with the page's form (with the `odden_vid` cookie value as `visitor_token`), increments `submissions_count`, and redirects back with the success message in the `success` session key. Like hosted forms, the submission is validated against the form's fields first (a failure redirects back with the error bag and records nothing, so `submissions_count` doesn't change). A page without a form returns 404 on submit.

The page is rendered by `odden-marketing::landing-page`.

## Capturing forms you didn't build with Odden

To capture leads from existing forms on your website, use [form auto-capture](web-tracking.md#form-auto-capture) in the tracking script.
