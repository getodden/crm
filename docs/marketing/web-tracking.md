---
title: Web tracking
description: Record pageviews with the odden.js client script, group them into visitor sessions, stitch anonymous sessions to contacts, and capture leads from existing website forms.
---

The marketing package includes first-party web tracking. A small script, `odden.js`, reports each pageview to your app and captures submissions of forms on your site. Pageviews are grouped into visitor sessions keyed by a visitor token, and when a visitor identifies themselves (by submitting a form, for example) their earlier anonymous sessions are linked to their contact record.

Paths below use the default route prefixes. See [public routes](../configuration.md#public-routes) to change them.

## The client script

| Method | URI | Route name |
| --- | --- | --- |
| `GET` | `/marketing/odden.js` | `odden.marketing.track.script` |

Add it to every page you want to track:

```html
<script src="https://your-app.test/marketing/odden.js" async></script>
```

The script is served with `Cache-Control: public, max-age=86400` and contains absolute URLs for the two endpoints below, so it honors your route prefix and domain. Once the page has loaded it:

1. Sends a pageview with `window.location.href`, the path, `document.title`, `document.referrer`, and the `utm_source`, `utm_medium` and `utm_campaign` query parameters.
2. Attaches a submit listener to every `<form>` on the page for [form auto-capture](#form-auto-capture).

Hosted [landing pages](forms-and-landing-pages.md#landing-pages) include the script automatically.

### Visitor tokens and cross-domain tracking

Every visitor is identified by a visitor token, one mechanism shared by `odden.js`, the [embed script](forms-and-landing-pages.md#embedding-a-form-on-another-site) and hosted pages:

1. On first use the script generates a random 32-character hex id (with `crypto.getRandomValues()`) and stores it on the site the script is embedded in: in `localStorage` under `_odden_vid`, and in a readable first-party cookie `_odden_vid` (one year, `path=/`, `SameSite=Lax`, `Secure` on HTTPS). Later pageviews reuse the stored id; if `localStorage` is cleared the cookie restores it.
2. The id is sent explicitly as `visitor_token` with every pageview, auto-capture and embedded form submission. Nothing depends on cookies of your Odden app's domain, and the requests don't send credentials, so tracking and stitching work when the scripts run on another domain.
3. Pageviews and auto-captures are sent as JSON with a `text/plain` content type (`fetch()` with `keepalive`, or `navigator.sendBeacon()` for auto-capture). That's a CORS-safelisted request, so browsers send it cross-domain without a preflight, and the endpoints decode the raw body as JSON. Responses aren't read, so no CORS headers are needed.

`odden.js` and `embed.js` include the same helper (`Odden\Marketing\Support\VisitorToken::javascript()`), so on a page that loads both, they use the same id.

The server validates the format: a token must be 16 to 64 letters, digits, `-` or `_` (`VisitorToken::PATTERN`). Malformed tokens are ignored, as if none were sent.

For hosted pages on your app's own domain ([landing pages](forms-and-landing-pages.md#landing-pages)), the pageview response also sets a `odden_vid` cookie (one year) holding the token in use. It's a regular Laravel cookie, encrypted and HttpOnly. Requests without an explicit `visitor_token` fall back to it, which is how landing page views and submissions are linked to the same session.

> Upgrading: visitors tracked by earlier versions of `odden.js` had only the server-issued `odden_vid` cookie, which the script can't read. They get a new id once, so their next visit starts a new session; earlier sessions stay as they were. On hosted pages the explicit id replaces the `odden_vid` cookie value with the first pageview.

## Pageview endpoint

| Method | URI | Route name | Auth |
| --- | --- | --- | --- |
| `POST` | `/marketing/track/pageview` | `odden.marketing.track.pageview` | Public. CSRF exempt, rate limited by `odden-public`. |

```bash
curl -X POST https://your-app.test/marketing/track/pageview \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"visitor_token": "3f9a1c0e8b7d4a6f9e2c1b0a5d4e3f21", "url": "https://www.example.com/pricing?plan=pro", "path": "/pricing", "title": "Pricing", "referer": "https://google.com", "utm_source": "google"}'
```

```json
{
    "status": "success",
    "session_id": 12,
    "visitor_token": "3f9a1c0e8b7d4a6f9e2c1b0a5d4e3f21"
}
```

The body can be sent as `application/json` or, as `odden.js` does, as a JSON object with a `text/plain` content type. Accepted fields: `visitor_token`, `url`, `path`, `title`, `referer`, `utm_source`, `utm_medium`, `utm_campaign`, and `duration_seconds`. Only `visitor_token` is validated (see [visitor tokens](#visitor-tokens-and-cross-domain-tracking)). If it's missing or malformed, a valid `odden_vid` cookie is used, and if there's none either a new random 40-character token is generated and returned. A pageview with a token that already has a session reuses it. `url` defaults to the `Referer` header, then `app.url`. `path` defaults to the path of `url`.

The `odden-public` limit is per IP address and defaults to 30 requests per minute (see [rate limits](../configuration.md#rate-limits)). A busy visitor who opens many pages quickly can hit it, so raise `ODDEN_PUBLIC_RATE_LIMIT` if you track high-traffic pages.

## Sessions and page views

Two models store the data:

- `Odden\Marketing\Models\VisitorSession`: one row per visitor token, with `contact_id` (once identified), `ip_address`, `user_agent`, `referer`, `utm_source`, `utm_medium`, `utm_campaign`, `first_seen_at`, and `last_seen_at`. The attribution fields are taken from the first request only. Relations: `contact`, `pageViews`.
- `Odden\Marketing\Models\PageView`: one row per pageview, with `session_id`, `contact_id`, `url`, `path`, `title`, `duration_seconds`, and `created_at` (no `updated_at`). Relations: `session`, `contact`.

```php
use Odden\Marketing\Models\VisitorSession;

$sessions = VisitorSession::query()
    ->where('contact_id', $contact->id)
    ->with('pageViews')
    ->get();
```

Each pageview also updates the session's `last_seen_at`.

### High-intent pages

When the session belongs to a known contact, visiting a path that starts with one of these prefixes applies the `property_match` [lead scoring](lead-scoring.md) event:

| Path prefix | Points in the log description |
| --- | --- |
| `/pricing` | 20 |
| `/demo` | 15 |
| `/enterprise` | 25 |
| `/quote` | 20 |

The prefixes are hard-coded in `RecordWebVisitAction`. The points in the table are what is added (and what the log entry `High Intent Web Visit: {path} (+{n} pts)` says): a visit scores as a `property_match` event with explicit points, so scoring rules don't change them. The event context carries `path` and `bonus`. Every visit to such a page scores again; there's no once-per-visitor limit.

### Recording visits from PHP

`Odden\Marketing\Actions\RecordWebVisitAction` does the work behind the endpoint. Call it to record server-side visits, for example from a middleware in your own app:

```php
use Odden\Marketing\Actions\RecordWebVisitAction;

$result = app(RecordWebVisitAction::class)->execute([
    'visitor_token' => 'server-side-visitor-1',
    'contact_id' => $contact->id,
    'url' => 'https://app.example.com/enterprise',
]);

$result['session'];    // VisitorSession
$result['page_view'];  // PageView, with path "/enterprise"
```

`url` is required. All other keys are optional: `visitor_token` (a malformed one is replaced by a random token), `contact_id`, `path`, `title`, `ip_address`, `user_agent`, `referer`, `utm_source`, `utm_medium`, `utm_campaign`, `duration_seconds`. A `contact_id` is set on the session only if the session doesn't have one yet. The HTTP endpoint never passes a `contact_id`; sessions are linked to contacts through stitching.

## Identity stitching

`Odden\Marketing\Actions\StitchVisitorToContactAction` links the sessions with a visitor token to a contact, and sets the contact on those sessions' page views that don't have one:

```php
use Odden\Marketing\Actions\StitchVisitorToContactAction;

$stitched = app(StitchVisitorToContactAction::class)->execute($visitorToken, $contact); // number of sessions updated
```

It runs automatically when a [form submission](forms-and-landing-pages.md#what-happens-on-submission) that resolves a contact by email includes a `visitor_token` field, and on [form auto-capture](#form-auto-capture). The [embed script](forms-and-landing-pages.md#embedding-a-form-on-another-site) sends the visitor id for you, and landing page submissions add the visitor's `odden_vid` cookie as `visitor_token`. For your own forms or the API endpoint, read `localStorage.getItem('_odden_vid')` on a page that loads `odden.js` and send it as `visitor_token`.

Because the token is readable by scripts on the host site, stitching only claims sessions that are still anonymous or already belong to the same contact; sessions linked to another contact are left alone. A malformed token stitches nothing and returns `0`.

## Form auto-capture

`odden.js` listens for the submission of every `<form>` on the page. On submit, if the form has an input of `type="email"` or with `email` in its name, the script collects:

- `email`
- `name` from an input named `name` or containing `full_name`
- `first_name` and `last_name` from inputs whose names contain `first` and `last`
- `phone` from an input of type `tel` or with `phone` in its name
- `page_url` and the `utm_*` query parameters

and sends them to the auto-capture endpoint. The form's own submission isn't affected.

| Method | URI | Route name | Auth |
| --- | --- | --- | --- |
| `POST` | `/marketing/forms/auto-capture` | `odden.marketing.forms.auto-capture` | Public. CSRF exempt, rate limited by `odden-public`. |

```bash
curl -X POST https://your-app.test/marketing/forms/auto-capture \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email": "lin@example.com", "name": "Lin Chen", "page_url": "https://www.example.com/contact"}'
```

```json
{
    "status": "success",
    "contact_id": 31,
    "is_new": true
}
```

A missing or invalid email returns `422` with `{"status": "error", "message": "Valid email address is required."}`.

The endpoint lowercases the email and loads or creates the contact. It fills `first_name`, `last_name` and `phone` only where they're empty, splitting `name` on the first space when no `first_name` is given. Scoring goes through the normal scoring action as a `form_submission` event with explicit points, so each change is logged in the lead score log, with the page in the event context, and can promote the contact's lifecycle stage.

- A new contact gets `lifecycle_stage` `marketing_qualified_lead` and a `lead_score` of 15.
- An existing contact gets 10 points added.

If a valid `visitor_token` is given (or the request carries a `odden_vid` cookie), the visitor's sessions are [stitched](#identity-stitching) to the contact, page views included. A `Website Form Auto-Captured` task activity is logged on the contact.

Auto-capture doesn't create a `FormSubmission`, store custom fields, or trigger workflows. Use a [Odden form](forms-and-landing-pages.md) when you need those.

The script sends the payload with `navigator.sendBeacon()` (falling back to `fetch()` with `keepalive`), so it's delivered even when the form submission navigates away. The body is the JSON payload in a `text/plain` `Blob`; the endpoint accepts it, as well as regular `application/json` requests. A `text/plain` body that isn't a JSON object is treated as empty and answered with `422`.
