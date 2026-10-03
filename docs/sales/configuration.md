---
title: Configuration and routes
description: Reference for the odden-sales config file, public routes, Artisan commands, scheduling, views, and events.
---

This page lists everything you can configure in `getodden/crm-sales`, plus the routes, commands, and events it registers. For settings shared by every Odden package, such as the user model and Core's rate limits, see [Configuration](../configuration.md).

## Publishing

```bash
php artisan vendor:publish --tag=odden-sales-config      # config/odden-sales.php
php artisan vendor:publish --tag=odden-sales-migrations  # copies migrations into database/migrations
```

Migrations are loaded from the package automatically, so publishing them is only needed if you want to edit them. Published copies keep their filenames, so Laravel treats them as the same migrations and doesn't run them twice.

## Config reference

### `tables`

Table names for every sales model. Change them before running the migrations.

| Key | Default |
| --- | --- |
| `odden-sales.tables.pipelines` | `odden_pipelines` |
| `odden-sales.tables.stages` | `odden_pipeline_stages` |
| `odden-sales.tables.deals` | `odden_deals` |
| `odden-sales.tables.stage_history` | `odden_deal_stage_history` |
| `odden-sales.tables.products` | `odden_deal_products` |
| `odden-sales.tables.quotes` | `odden_quotes` |
| `odden-sales.tables.quote_items` | `odden_quote_items` |
| `odden-sales.tables.automations` | `odden_stage_automations` |
| `odden-sales.tables.quotas` | `odden_sales_quotas` |
| `odden-sales.tables.email_templates` | `odden_sales_email_templates` |
| `odden-sales.tables.sequences` | `odden_sales_sequences` |
| `odden-sales.tables.sequence_enrollments` | `odden_sales_sequence_enrollments` |
| `odden-sales.tables.playbooks` | `odden_sales_playbooks` |
| `odden-sales.tables.meeting_links` | `odden_sales_meeting_links` |
| `odden-sales.tables.meeting_bookings` | `odden_sales_meeting_bookings` |
| `odden-sales.tables.lead_routing_rules` | `odden_sales_lead_routing_rules` |

### `default_currency`

```php
'default_currency' => env('ODDEN_DEFAULT_CURRENCY', 'USD'),
```

Used by `Odden\Sales\Support\Money::format()` when a record has no currency of its own, for example in the forecast widget. New deals, quotas, and quotes still take their currency from the database column default (`USD`) or from what you pass. Generated quotes copy the deal's currency.

### `mail`

```php
'mail' => [
    'mailer' => env('ODDEN_SALES_MAILER'),
    'connection' => env('ODDEN_SALES_QUEUE_CONNECTION'),
    'queue' => env('ODDEN_SALES_MAIL_QUEUE'),

    'from' => [
        'address' => env('ODDEN_SALES_FROM_ADDRESS'),
        'name' => env('ODDEN_SALES_FROM_NAME'),
    ],

    'sequences' => [
        'send_as_owner' => (bool) env('ODDEN_SALES_SEND_AS_OWNER', false),
    ],
],
```

Sales sends two kinds of email: [sequence email steps](sequences.md#email-steps) (`Odden\Sales\Mail\SequenceStepMail`) and [meeting confirmations](meeting-links.md#confirmation-emails) (`Odden\Sales\Mail\MeetingBookedMail`). Both implement `ShouldQueue` and are dispatched after the surrounding database transaction commits, so nothing is sent during the web request or command. **Run a queue worker** (`php artisan queue:work`, adding `--queue=` if you set a queue name); with the `sync` queue connection, mail is sent immediately instead.

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `mail.mailer` | `ODDEN_SALES_MAILER` | `null` | Mailer from `config/mail.php` to send with. `null` uses the default mailer. |
| `mail.connection` | `ODDEN_SALES_QUEUE_CONNECTION` | `null` | Queue connection. `null` uses the default connection. |
| `mail.queue` | `ODDEN_SALES_MAIL_QUEUE` | `null` | Queue name. `null` uses the connection's default queue. |
| `mail.from.address` | `ODDEN_SALES_FROM_ADDRESS` | `null` | From address for Sales email. `null` uses your app's `mail.from`. |
| `mail.from.name` | `ODDEN_SALES_FROM_NAME` | `null` | From name, used with `mail.from.address`. |
| `mail.sequences.send_as_owner` | `ODDEN_SALES_SEND_AS_OWNER` | `false` | Send sequence emails from the enrollment owner's address and name. When `false` the owner is only the reply-to. |

### `meetings`

```php
'meetings' => [
    'default_working_hours' => [
        'monday' => ['09:00-17:00'],
        'tuesday' => ['09:00-17:00'],
        'wednesday' => ['09:00-17:00'],
        'thursday' => ['09:00-17:00'],
        'friday' => ['09:00-17:00'],
    ],

    'booking_window_days' => (int) env('ODDEN_SALES_BOOKING_WINDOW_DAYS', 60),
],
```

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `meetings.default_working_hours` | | Monday to Friday, `09:00-17:00` | Used by meeting links whose `working_hours` is empty. Same shape as [working hours](meeting-links.md#working-hours), in each link's timezone. |
| `meetings.booking_window_days` | `ODDEN_SALES_BOOKING_WINDOW_DAYS` | `60` | How many days ahead visitors can book. |

### `routes`

```php
'routes' => [
    'enabled' => (bool) env('ODDEN_SALES_ROUTES_ENABLED', true),

    'web' => [
        'domain' => env('ODDEN_SALES_DOMAIN'),
        'prefix' => env('ODDEN_SALES_PREFIX', ''),
        'middleware' => ['web'],
    ],
],
```

| Key | Env | Default | Effect |
| --- | --- | --- | --- |
| `routes.enabled` | `ODDEN_SALES_ROUTES_ENABLED` | `true` | Set to `false` to skip registering the public routes. |
| `routes.web.domain` | `ODDEN_SALES_DOMAIN` | `null` | Serve the routes on one domain, for example `deals.example.com`. |
| `routes.web.prefix` | `ODDEN_SALES_PREFIX` | `''` | URL prefix, for example `sales` gives `/sales/quotes/{token}`. |
| `routes.web.middleware` | | `['web']` | Middleware for the route group. Keep `web`: the forms need sessions and CSRF protection. |

Empty values are dropped, so a `null` domain or empty prefix applies no constraint.

```env
ODDEN_SALES_DOMAIN=proposals.example.com
ODDEN_SALES_PREFIX=
```

## Public routes

These routes have no authentication; access is by quote token or meeting slug.

| Method | URI | Name | Purpose |
| --- | --- | --- | --- |
| GET | `/quotes/{token}` | `odden.quotes.show` | Customer quote page. See [Quotes](quotes.md#sharing-the-quote). |
| POST | `/quotes/{token}/accept` | `odden.quotes.accept` | Accept and sign a quote. |
| GET | `/meet/{slug}` | `odden.meetings.show` | Meeting booking page. See [Meeting links](meeting-links.md). |
| POST | `/meet/{slug}/book` | `odden.meetings.book` | Book a meeting. |

The two POST routes use the `throttle:odden-public` middleware. Core defines that limiter: `odden-core.rate_limits.public` requests per minute per IP, default `30` (`ODDEN_PUBLIC_RATE_LIMIT`).

If you set `routes.enabled` to `false` and register your own routes, keep the four route names. The package's views and controllers generate URLs and redirects with them.

## Views

Views are registered under the `odden-sales` namespace:

| View | Used by |
| --- | --- |
| `odden-sales::quotes.public-portal` | `odden.quotes.show` |
| `odden-sales::meetings.book` | `odden.meetings.show` |
| `odden-sales::mail.meeting-booked` | Body of the meeting confirmation email. |

There is no publish tag for views. To override one, create a file with the same path under `resources/views/vendor/odden-sales/` in your app.

## Artisan commands

| Command | What it does |
| --- | --- |
| `sales:process-cadences` | Runs due sequence steps, queuing email steps for delivery, and prints a summary table. See [Processing due steps](sequences.md#processing-due-steps). |
| `sales:expire-quotes` | Sets `draft`, `sent`, and `approved` quotes whose `expires_at` is before today to `expired`. See [Expiring stale quotes](quotes.md#expiring-stale-quotes). |

Neither command takes arguments or options.

## Scheduling

The package does not add anything to the scheduler. Sequences don't advance and quotes don't expire until you schedule the commands, for example in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sales:process-cadences')->hourly();
Schedule::command('sales:expire-quotes')->daily();
```

Sequence steps are due by date, so running `sales:process-cadences` more than once a day only matters for picking up manual steps that reps have completed. Running it again never sends the same email step twice.

Sequence emails and meeting confirmations are queued, so a queue worker must also be running for them to be delivered. See [`mail`](#mail).

## Events

| Event | Dispatched when |
| --- | --- |
| `Odden\Sales\Events\DealMovedStage` | A deal changes stage. |
| `Odden\Sales\Events\DealWon` | A deal enters a closed won stage. |
| `Odden\Sales\Events\DealLost` | A deal enters a closed lost stage. |

Properties and timing are described in [Deals](deals.md#events). The package registers no listeners for them.

## Side effects at a glance

The only mail Sales sends is sequence email steps and meeting confirmations, and both are queued (see [`mail`](#mail)). These are the writes that happen in the background of a call:

| Trigger | Side effect |
| --- | --- |
| Deal enters a stage | Stage automations run; stage history row written; events dispatched; on won/lost, associated contacts' active sequence enrollments are unenrolled. |
| `DealProduct` saved, deleted, or restored | Deal `amount` recalculated. |
| `QuoteItem` saved or deleted | Quote `subtotal` and `total_amount` recalculated. |
| Quote page viewed | Draft quote becomes `sent`; a view note is logged on the deal (at most every two hours). |
| Quote accepted | Deal moved to closed won, `amount` set from the quote, note and owner task logged. |
| Lead routed | `owner_id` updated; note logged. |
| Contact enrolled in a sequence | `New` lead status becomes `InProgress`. |
| Meeting booked | Slot re-checked under a lock; contact created or matched; meeting activity logged; active sequence enrollments unenrolled; booking recorded; confirmation emails with an .ics invite queued to the visitor and the rep. |
| Sequence email step processed | Email queued to the contact (or the step skipped, with the reason logged); email activity logged; contact marked contacted. |
