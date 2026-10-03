---
title: Configuration reference
description: Every odden-service config key and environment variable, the full route list, and how to replace the package routes.
---

The service module reads its settings from `config/odden-service.php`. Publish the file only if you need to change something that has no environment variable:

```bash
php artisan vendor:publish --tag=odden-service-config
```

Settings shared by all Odden modules, such as the user model and rate limits, are covered in [Configuration](../configuration.md).

## Environment variables

| Variable | Config key | Default |
| :--- | :--- | :--- |
| `ODDEN_SERVICE_ROUTES_ENABLED` | `odden-service.routes.enabled` | `true` |
| `ODDEN_SERVICE_DOMAIN` | `odden-service.routes.web.domain` and `odden-service.routes.api.domain` | `null` (any domain) |
| `ODDEN_SERVICE_PREFIX` | `odden-service.routes.web.prefix` | `''` (no prefix) |
| `ODDEN_SERVICE_API_PREFIX` | `odden-service.routes.api.prefix` | `api/service` |
| `ODDEN_SERVICE_API_TOKEN` | `odden-service.api.token` | `null` (token endpoints disabled) |
| `ODDEN_SERVICE_INBOUND_REQUIRE_AUTH` | `odden-service.inbound_email.require_authenticated_sender` | `false` |
| `ODDEN_SERVICE_REOPEN_ON_CUSTOMER_REPLY` | `odden-service.reopen_on_customer_reply` | `true` |
| `ODDEN_SERVICE_CHAT_CONFIRMATION_EMAIL` | `odden-service.chat.confirmation_email` | `false` |
| `ODDEN_SERVICE_NOTIFICATIONS_CONNECTION` | `odden-service.notifications.connection` | `null` (default queue connection) |
| `ODDEN_SERVICE_NOTIFICATIONS_QUEUE` | `odden-service.notifications.queue` | `null` (the connection's default queue) |

The rate limits come from Core: `ODDEN_PUBLIC_RATE_LIMIT` (default 30 per minute) and `ODDEN_API_RATE_LIMIT` (default 600 per minute). See [Rate limits](#rate-limits).

## Config keys

### Tables

```php
'tables' => [
    'tickets' => 'odden_service_tickets',
    'messages' => 'odden_service_ticket_messages',
    'sla_policies' => 'odden_service_sla_policies',
    'articles' => 'odden_service_articles',
    'canned_responses' => 'odden_service_canned_responses',
    'routing_rules' => 'odden_service_routing_rules',
],
```

The models and migrations both read these names. Change them before you run the migrations. The migrations also read `odden-core.tables.contacts` and `odden-core.tables.companies` for their foreign keys.

### Ticket defaults

```php
'defaults' => [
    'priority' => 'medium',
    'source' => 'web_portal',
    'prefix' => 'TICK',
],
```

`prefix` is used for generated ticket numbers (`TICK-2026-7WBPJ`). Generated numbers keep the prefix as written. [Inbound email](inbound-email.md#threading-replies) doesn't use ticket numbers to thread replies.

`priority` and `source` are not read by the package. A new ticket's defaults come from the model and the database columns (`medium` and `web_portal`) and from the arguments you pass to `CreateTicketAction`.

### Routes

```php
'routes' => [
    'enabled' => (bool) env('ODDEN_SERVICE_ROUTES_ENABLED', true),

    'web' => [
        'domain' => env('ODDEN_SERVICE_DOMAIN'),
        'prefix' => env('ODDEN_SERVICE_PREFIX', ''),
        'middleware' => ['web'],
    ],

    'api' => [
        'domain' => env('ODDEN_SERVICE_DOMAIN'),
        'prefix' => env('ODDEN_SERVICE_API_PREFIX', 'api/service'),
        'middleware' => ['web'],
    ],
],
```

Each group's `domain`, `prefix`, and `middleware` are passed to `Route::group()`. Empty values are dropped. For example, `ODDEN_SERVICE_PREFIX=care` serves the help center at `/care/help`, and `ODDEN_SERVICE_DOMAIN=support.example.com` serves both groups only on that host.

Both groups use the `web` middleware group by default. The browser-facing chat and deflection endpoints and the email webhook remove Laravel's CSRF middleware themselves, so they work from other sites and servers. If you set the `api` group's middleware to `['api']`, sessions are no longer started for widget requests; nothing in the API endpoints depends on the session.

Generate links with `route()` and the route names below, so prefix and domain changes are picked up.

### API token

```php
'api' => [
    'token' => env('ODDEN_SERVICE_API_TOKEN'),
],
```

Protects the [inbound email webhook](inbound-email.md#api-token). Until it is set, that endpoint returns `403`.

### Inbound email

```php
'inbound_email' => [
    'require_authenticated_sender' => (bool) env('ODDEN_SERVICE_INBOUND_REQUIRE_AUTH', false),
],
```

When `true`, the [inbound email webhook](inbound-email.md#requiring-sender-authentication) only threads a reply onto an existing ticket if the request has `sender_authenticated` set to a true value or `dmarc` set to `pass`. Otherwise the email opens a new ticket.

### Customer replies

```php
'reopen_on_customer_reply' => (bool) env('ODDEN_SERVICE_REOPEN_ON_CUSTOMER_REPLY', true),
```

When `true` (the default), a customer reply by email, on the portal, or in the chat widget reopens a `Resolved` or `Closed` ticket: the status becomes `Open` and `resolved_at` and `closed_at` are cleared. When `false`, the reply is added and the status is left alone. Either way, a reply by email to a [merged](routing.md#merging-tickets) ticket is posted on its primary ticket; see [Replies to merged tickets](routing.md#replies-to-merged-tickets) for the portal and chat rules. See [Statuses](tickets.md#statuses).

### Chat widget

```php
'chat' => [
    'confirmation_email' => (bool) env('ODDEN_SERVICE_CHAT_CONFIRMATION_EMAIL', false),
],
```

Whether a ticket started from the [chat widget](chat-widget.md#confirmation-email) emails the visitor `TicketCreatedNotification`. Off by default, because the chat endpoint is public and doesn't verify the email address. Chat tickets are routed and logged on the contact's timeline either way.

### Notifications

```php
'notifications' => [
    'connection' => env('ODDEN_SERVICE_NOTIFICATIONS_CONNECTION'),
    'queue' => env('ODDEN_SERVICE_NOTIFICATIONS_QUEUE'),
],
```

The [ticket notifications](tickets.md#notifications) are queued (`ShouldQueue`) on this connection and queue. `null` uses your default queue connection (`QUEUE_CONNECTION`) and its default queue. Run a worker for the queue, for example `php artisan queue:work --queue=support-mail,default` when `ODDEN_SERVICE_NOTIFICATIONS_QUEUE=support-mail`. With the `sync` connection, notifications are sent during the request.

## Routes

The `web` group (no prefix by default):

| Method | URI | Name | Throttle | Page |
| :--- | :--- | :--- | :--- | :--- |
| GET | `/help` | `odden.help.index` | | [Knowledge base](knowledge-base.md#the-help-center) |
| GET | `/help/{slug}` | `odden.help.show` | | |
| POST | `/help/{slug}/vote` | `odden.help.vote` | `odden-public` | |
| GET | `/support` | `odden.support.create` | | [Support portal](customer-portal.md) |
| POST | `/support` | `odden.support.store` | `odden-public` | |
| GET | `/support/tickets/{token}` | `odden.support.show` | | |
| POST | `/support/tickets/{token}/reply` | `odden.support.reply` | `odden-public` | |
| GET | `/support/rate/{token}` | `odden.support.rate` | | [CSAT](customer-portal.md#csat-surveys) |
| POST | `/support/rate/{token}` | `odden.support.submitRating` | `odden-public` | |

The `api` group (prefix `api/service` by default):

| Method | URI | Name | Throttle | CSRF | Auth |
| :--- | :--- | :--- | :--- | :--- | :--- |
| POST | `/inbound-email` | `odden.service.inbound-email` | `odden-api` | Exempt | API token |
| GET | `/knowledge/suggest` | `odden.service.knowledge.suggest` | | | |
| POST | `/knowledge/deflect` | `odden.service.knowledge.deflect` | `odden-public` | Exempt | |
| POST | `/chat/start` | `odden.service.chat.start` | `odden-public` | Exempt | |
| POST | `/chat/{token}/message` | `odden.service.chat.message` | `odden-public` | Exempt | |
| GET | `/chat/{token}/messages` | `odden.service.chat.messages` | | | |

POST routes in the `web` group keep CSRF protection; the bundled forms include the token.

## Rate limits

The throttled routes use Core's limiters, keyed by IP address and module:

- `odden-public`: `odden-core.rate_limits.public`, default 30 requests per minute.
- `odden-poll`: `odden-core.rate_limits.poll`, default 120 requests per minute.
- `odden-api`: `odden-core.rate_limits.api`, default 600 requests per minute.

Each limiter keeps one counter per IP for each Odden module, shared by that module's routes that use it. A visitor who sends chat messages, votes on articles, and submits the support form is counted once against the same 30 per minute for the service module, but marketing or sales routes have their own counters. If many customers reach your app through one proxy address, configure trusted proxies as described in [Rate limits](../configuration.md#rate-limits).

`GET /chat/{token}/messages`, which the chat widget polls every 4 seconds, and `GET /knowledge/suggest` use the separate `odden-poll` limiter (120 per minute by default).

## Using your own routes

Set `ODDEN_SERVICE_ROUTES_ENABLED=false` to stop the package from registering any routes, then register the ones you want in your app. Keep the route names: the models, notifications, actions, and bundled views generate links with them.

| Name | Used by |
| :--- | :--- |
| `odden.support.show` | `Ticket::getPortalUrl()`, customer emails, portal redirects |
| `odden.support.rate` | `Ticket::getCsatUrl()`, the resolution email |
| `odden.help.show` | `DeflectTicketAction` result URLs |
| `odden.help.index`, `odden.help.vote`, `odden.support.create`, `odden.support.store`, `odden.support.reply`, `odden.support.submitRating` | The bundled views |
| `odden.service.knowledge.suggest`, `odden.service.knowledge.deflect` | The support form's suggestion script |

The simplest starting point is the package's own `routes/web.php`. This example puts the help center behind your app's login and moves the email webhook to a different path:

```php
// routes/web.php
use Odden\Core\Http\Middleware\RequireApiToken;
use Odden\Core\Support\CsrfExemption;
use Odden\Service\Http\Controllers\HelpCenterController;
use Odden\Service\Http\Controllers\InboundEmailWebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function (): void {
    Route::get('/help', [HelpCenterController::class, 'index'])->name('odden.help.index');
    Route::get('/help/{slug}', [HelpCenterController::class, 'show'])->name('odden.help.show');
    Route::post('/help/{slug}/vote', [HelpCenterController::class, 'vote'])
        ->middleware('throttle:odden-public')
        ->name('odden.help.vote');
});

Route::post('/webhooks/support-email', InboundEmailWebhookController::class)
    ->withoutMiddleware(CsrfExemption::middleware())
    ->middleware([RequireApiToken::class.':odden-service.api.token', 'throttle:odden-api'])
    ->name('odden.service.inbound-email');
```

Routes in `routes/web.php` already run the `web` middleware group. `CsrfExemption::middleware()` returns the CSRF middleware classes that exist in your Laravel version (`ValidateCsrfToken`, and `PreventRequestForgery` on Laravel 13), so the webhook is exempt on both. You still need to register the support portal routes if customers will follow the links in their emails.

## Migrations

The migrations load automatically. To edit them before running, publish them:

```bash
php artisan vendor:publish --tag=odden-service-migrations
```

They create the six tables above. They require Core's contacts and companies tables and your users table, so run them after Core's migrations.

## Demo data

`Odden\Service\Database\Seeders\ServiceDatabaseSeeder` creates sample SLA policies, articles, canned responses, routing rules, and tickets. It also creates three users (`admin@odden.test`, `alex.mercer@odden.test`, `beth.caldwell@odden.test`) with the password `password`, and attaches tickets to existing contacts and companies. Use it only in local environments.

```bash
php artisan db:seed --class="Odden\Service\Database\Seeders\ServiceDatabaseSeeder"
```
