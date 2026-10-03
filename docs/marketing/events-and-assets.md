---
title: Events and gated assets
description: Register contacts for webinars and events, record attendance from a webhook, and track downloads of lead magnets through signed links.
---

Events and assets are two common lead magnets. Contacts register for an event through a public endpoint, and a server-to-server webhook (from Zoom, Google Meet, or Zapier) marks who attended. Gated assets, such as reports and guides, are downloaded through a link that can identify the contact who received it. Both score the contact and can enroll them in [workflows](workflows.md#triggers).

Paths below use the default route prefixes. See [public routes](../configuration.md#public-routes) to change them.

## Events

`Odden\Marketing\Models\MarketingEvent`:

```php
use Odden\Marketing\Models\MarketingEvent;

$event = MarketingEvent::create([
    'title' => 'Pipeline Masterclass',
    'event_type' => 'webinar',
    'starts_at' => now()->addWeek(),
    'virtual_meeting_url' => 'https://zoom.us/j/123',
    'capacity' => 500,
]);

$event->slug; // "pipeline-masterclass"
```

| Attribute | Notes |
| --- | --- |
| `title` | Required. |
| `slug` | Unique. Generated from `title` when empty. |
| `description` | Free text. |
| `event_type` | Free string, default `webinar`. Suggested: `webinar`, `in_person`, `workshop`, `round_table`. |
| `status` | Free string, default `scheduled`. Suggested: `draft`, `scheduled`, `live`, `completed`, `cancelled`. |
| `starts_at`, `ends_at`, `timezone` | `timezone` defaults to `UTC`. |
| `virtual_meeting_url`, `location` | Returned to registrants. |
| `capacity` | Optional maximum number of registrations. |
| `is_published` | Defaults to `true`. |
| `registrations_count`, `attendees_count` | Maintained by the actions below. |

Helpers: `attendanceRate(): float` (attendees / registrations × 100, one decimal) and `isFull(): bool` (`capacity` set and reached). Relations: `registrations` and `contacts` (with `status`, `registered_at`, `attended_at` on the pivot).

The registration endpoint only accepts new registrations for events that are published (otherwise `404`), have a `status` of `scheduled` or `live` (`MarketingEvent::acceptsRegistrations()`) and aren't full (`isFull()`). A closed or full event returns `409` with `success: false` and a message. A contact who is already registered can re-submit, even when the event is full or closed.

Registrations are `Odden\Marketing\Models\MarketingEventRegistration` rows: `event_id`, `contact_id` (unique together), `status` (`registered`, `attended`, `no_show`, `cancelled` by convention), `registered_at`, `attended_at`, and `utm_source`, `utm_medium`, `utm_campaign`.

### Registration endpoint

| Method | URI | Route name | Auth |
| --- | --- | --- | --- |
| `POST` | `/api/marketing/events/{slug}/register` | `odden.marketing.events.register` | Public. CSRF exempt, rate limited by `odden-public`. |

```bash
curl -X POST https://your-app.test/api/marketing/events/pipeline-masterclass/register \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email": "ana@example.com", "first_name": "Ana", "utm_source": "newsletter"}'
```

| Field | Rules |
| --- | --- |
| `email` | Required, valid email, max 255. |
| `first_name`, `last_name` | Optional, max 255. |
| `utm_source`, `utm_medium`, `utm_campaign` | Optional, max 255. |

```json
{
    "success": true,
    "message": "Successfully registered for Pipeline Masterclass",
    "event": {
        "title": "Pipeline Masterclass",
        "starts_at": "2026-10-09T16:45:00+00:00",
        "virtual_meeting_url": "https://zoom.us/j/123"
    },
    "registration": {
        "id": 1,
        "status": "registered"
    }
}
```

The contact is matched by email ignoring case and surrounding whitespace (new contacts are stored lowercased), and created if missing, with `first_name` defaulting to `Attendee`. A placeholder `Attendee` name is replaced when a later registration includes a first name. An unknown slug returns `404`.

`Odden\Marketing\Actions\RegisterContactForEventAction` then creates or updates the registration. You can call it directly:

```php
use Odden\Marketing\Actions\RegisterContactForEventAction;

$registration = app(RegisterContactForEventAction::class)->execute(
    event: $event,
    contact: $contact,
    utm: ['utm_source' => 'partner'],
);
```

On the first registration of a contact for an event it increments `registrations_count`, logs a `Registered for Event: {title}` task, and adds 10 points (`property_match` [scoring event](lead-scoring.md)). Registering again resets the registration to `registered` with a new `registered_at` (an `attended` registration keeps its status and `registered_at`) and keeps earlier UTM values unless new ones are given, but doesn't score again.

### Attendance webhook

| Method | URI | Route name | Auth |
| --- | --- | --- | --- |
| `POST` | `/api/marketing/events/{slug}/attendance-webhook` | `odden.marketing.events.attendance-webhook` | `ODDEN_MARKETING_API_TOKEN`, rate limited by `odden-api`. CSRF exempt. |

Send the token as described in [API tokens](../configuration.md#api-tokens).

```bash
curl -X POST https://your-app.test/api/marketing/events/pipeline-masterclass/attendance-webhook \
  -H "Authorization: Bearer $ODDEN_MARKETING_API_TOKEN" \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email": "ana@example.com", "status": "attended"}'
```

```json
{
    "success": true,
    "status": "attended",
    "attended_at": "2026-10-09T17:02:11+00:00"
}
```

`status` defaults to `attended` and must be one of `registered`, `attended`, `no_show` or `cancelled` (`MarketingEventRegistration::STATUSES`), otherwise the response is `422`. The contact is looked up by email, ignoring case and surrounding whitespace. Errors:

| Status | Body |
| --- | --- |
| `422` | `{"error": "Email required"}` |
| `404` | `{"error": "Contact not found"}` or `{"error": "Registration not found"}` (also a plain 404 for an unknown slug) |

`Odden\Marketing\Actions\UpdateAttendanceStatusAction::execute(MarketingEventRegistration $registration, string $status)` does the work and can be called directly. It recounts the event's `attendees_count` from registrations with status `attended`. When a registration becomes `attended` for the first time it also:

- sets `attended_at`;
- logs an `Attended Event: {title}` task;
- adds 20 points (`property_match`);
- enrolls the contact in active `event_attended` workflows whose `trigger_config.event_id` is this event or unset.

## Gated assets

`Odden\Marketing\Models\MarketingAsset` represents a downloadable file or link:

```php
use Odden\Marketing\Models\MarketingAsset;

$asset = MarketingAsset::create([
    'name' => 'State of RevOps 2026',
    'asset_type' => 'report',
    'external_url' => 'https://cdn.example.com/state-of-revops-2026.pdf',
    'lead_score_points' => 25,
]);
```

| Attribute | Notes |
| --- | --- |
| `name` | Required. |
| `slug` | Unique. Generated from `name` when empty. |
| `asset_type` | Free string, default `whitepaper`. Suggested: `whitepaper`, `case_study`, `guide`, `template`, `spreadsheet`, `report`. |
| `external_url` | When set, downloads redirect here. |
| `file_path` | Otherwise, a path relative to `storage/app` that's streamed as a download. |
| `description`, `file_size_kb` | Informational. |
| `lead_score_points` | Points added per identified download. Default 15. |
| `is_gated`, `is_active` | Default `true`. Stored only; the download route doesn't check them. |
| `downloads_count`, `unique_leads_count` | Maintained by the download tracker. |

Relations: `downloads` (`MarketingAssetDownload` rows) and `downloadingContacts`.

### Signed download links

| Method | URI | Route name | Auth |
| --- | --- | --- | --- |
| `GET` | `/marketing/assets/{slug}/download` | `odden.marketing.assets.download` | Public |

`getDownloadUrl(?Contact $contact = null): string` builds the link. With a contact, it adds `contact_id` and a `signature`, an HMAC-SHA256 of the asset id and contact id keyed with `app.key`:

```php
$url = $asset->getDownloadUrl($contact);
// https://your-app.test/marketing/assets/state-of-revops-2026/download?contact_id=17&signature=9b1e...
```

Put this link in the email that delivers the asset (for example after a form submission) so the download is attributed to the contact. Links don't expire. A link without a valid signature still downloads the asset, but anonymously; a bare `contact_id` never identifies anyone. `resolveSignedContact(mixed $contactId, mixed $signature): ?Contact` checks a signature yourself.

The route never asks for a form: the "gate" is whatever you put in front of the link. If the asset has neither `external_url` nor an existing `file_path`, the route redirects back with a `success` flash message and nothing is downloaded.

### Download tracking

Every request to the download route runs `Odden\Marketing\Actions\TrackAssetDownloadAction`, which:

- stores a `MarketingAssetDownload` (`contact_id`, `ip_address`, `user_agent`, a random `download_token`, `downloaded_at`);
- increments `downloads_count`, and `unique_leads_count` on a contact's first download.

For an identified contact it also:

- logs a `Downloaded Asset: {name}` task;
- adds `lead_score_points` (`property_match`, skipped when 0), on every download;
- enrolls the contact in active `asset_downloaded` workflows whose `trigger_config.asset_id` is this asset or unset.

To record a download from your own code:

```php
use Odden\Marketing\Actions\TrackAssetDownloadAction;

$download = app(TrackAssetDownloadAction::class)->execute(asset: $asset, contact: $contact);
```

The full signature is `execute(MarketingAsset $asset, ?Contact $contact = null, ?string $ipAddress = null, ?string $userAgent = null): MarketingAssetDownload`.
