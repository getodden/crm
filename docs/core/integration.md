---
title: Users, routes, and configuration
description: How Core resolves your user model, the route helpers, API token middleware, and rate limiters it provides to the other modules, and every odden-core config key.
---

Core provides a few helpers that every Odden module uses to fit into your app: user model resolution, route group attributes, a shared-token middleware for server-to-server endpoints, a CSRF exemption helper, and two rate limiters. You can use them in your own routes too. For settings shared across packages, see [Configuration](../configuration.md).

## The user model

Odden never references `App\Models\User`. Owners, creators, and authors (`owner_id`, `creator_id`, `user_id`, `created_by_id`) point at whatever model `Odden\Core\Support\UserModel` resolves:

1. `odden-core.user_model`, set with the `ODDEN_USER_MODEL` environment variable.
2. Otherwise `auth.providers.users.model`.

```env
ODDEN_USER_MODEL="App\Models\Admin"
```

The class must extend `Illuminate\Database\Eloquent\Model`, or `UserModel::className()` throws a `RuntimeException`.

The migrations create foreign keys to this model's table, so set it before you run them.

```php
use Odden\Core\Support\UserModel;

UserModel::className();                 // "App\Models\User"
UserModel::query()->where('email', $email)->first();
UserModel::make();                      // new, unsaved instance
UserModel::table();                     // "users"
UserModel::displayName($contact->owner);           // the user's name, or "Unknown"
UserModel::displayName($contact->owner, 'System'); // custom fallback
```

`displayName(?Model $user, string $fallback = 'Unknown')` returns the user's `name` attribute when it's a non-empty string, and the fallback otherwise.

## Route groups

Modules keep their route settings in config as a `domain`, `prefix`, and `middleware` block. `Odden\Core\Support\RouteGroup::attributes(string $configKey): array` turns that block into `Route::group()` attributes. It drops `null`, empty-string, and empty-array values, so an unset `ODDEN_*_DOMAIN` doesn't pin routes to an empty domain. A missing key returns `[]`.

```php
// config/acme-crm.php
'routes' => [
    'webhooks' => [
        'domain' => env('ACME_CRM_DOMAIN'),
        'prefix' => 'hooks/acme',
        'middleware' => [],
    ],
],
```

```php
// routes/web.php
use Odden\Core\Http\Middleware\RequireApiToken;
use Odden\Core\Support\CsrfExemption;
use Odden\Core\Support\RouteGroup;
use Illuminate\Support\Facades\Route;

Route::group(RouteGroup::attributes('acme-crm.routes.webhooks'), function (): void {
    Route::post('inbound', InboundWebhookController::class)
        ->withoutMiddleware(CsrfExemption::middleware())
        ->middleware([RequireApiToken::class.':acme-crm.api.token', 'throttle:odden-api'])
        ->name('acme-crm.webhooks.inbound');
});
```

The Marketing, Sales, and Service packages build their routes this way from `odden-marketing.routes.*`, `odden-sales.routes.web`, and `odden-service.routes.*`.

## API token middleware

`Odden\Core\Http\Middleware\RequireApiToken` protects server-to-server endpoints such as webhooks and sending APIs with a shared secret. Its parameter is the config key that holds the token:

```php
->middleware(RequireApiToken::class.':odden-marketing.api.token')
```

- If the config value is not a non-empty string, every request gets a `403` "This endpoint is disabled until an API token is configured." Endpoints stay closed until you set a token.
- The client can send the token as a bearer token (`Authorization: Bearer <token>`), as an `X-Odden-Token` header, or as a `?token=` query parameter for providers that can only be given a URL. They're checked in that order.
- A missing or wrong token gets a `401` "Invalid or missing API token." The comparison uses `hash_equals()`.

Query-string tokens can end up in access logs. Prefer the header forms where the caller supports them.

## CSRF exemption

Webhooks and cross-site form posts can't carry Laravel's CSRF token. `Odden\Core\Support\CsrfExemption::middleware()` returns the CSRF middleware classes present in the installed framework, so you can pass them to `withoutMiddleware()`. It returns whichever of `PreventRequestForgery` and `ValidateCsrfToken` exist. Laravel 13 renamed the CSRF middleware to `PreventRequestForgery`, so on Laravel 13 `withoutMiddleware(ValidateCsrfToken::class)` alone leaves CSRF protection on.

```php
Route::post('inbound', InboundWebhookController::class)
    ->withoutMiddleware(CsrfExemption::middleware());
```

## Rate limiters

`CoreServiceProvider` defines three named limiters, keyed by client IP and route module (the first two segments of the route name, such as `odden.service`) and counted per minute:

| Limiter | Use | Config key | Env var | Default |
| --- | --- | --- | --- | --- |
| `odden-public` | Browser-facing submissions: forms, chat, portal replies | `odden-core.rate_limits.public` | `ODDEN_PUBLIC_RATE_LIMIT` | 30 |
| `odden-poll` | Read endpoints clients call repeatedly: chat polling, article suggestions | `odden-core.rate_limits.poll` | `ODDEN_POLL_RATE_LIMIT` | 120 |
| `odden-api` | Token-authenticated webhooks and sending APIs | `odden-core.rate_limits.api` | `ODDEN_API_RATE_LIMIT` | 600 |

```php
Route::post('contact-us', ContactFormController::class)->middleware('throttle:odden-public');
```

```env
ODDEN_PUBLIC_RATE_LIMIT=30
ODDEN_POLL_RATE_LIMIT=120
ODDEN_API_RATE_LIMIT=600
```

Behind a load balancer or proxy, configure Laravel's trusted proxies so `$request->ip()` returns the client's address. Otherwise all traffic shares one bucket.

## Configuration reference

Publish the file with `php artisan vendor:publish --tag=odden-core-config` to change values that have no environment variable.

| Key | Default | Description |
| --- | --- | --- |
| `tables.contacts` | `odden_contacts` | Table names for each model. Read by the models and migrations, so set them before migrating. |
| `tables.companies` | `odden_companies` | |
| `tables.properties` | `odden_properties` | `PropertyDefinition` table. |
| `tables.associations` | `odden_associations` | |
| `tables.association_types` | `odden_association_types` | |
| `tables.activities` | `odden_activities` | |
| `tables.property_history` | `odden_property_history` | |
| `tables.lists` | `odden_lists` | |
| `tables.list_memberships` | `odden_list_memberships` | |
| `tables.lifecycle_stage_transitions` | `odden_lifecycle_stage_transitions` | |
| `tables.custom_object_definitions` | `odden_custom_object_definitions` | |
| `tables.custom_object_records` | `odden_custom_object_records` | |
| `user_model` | `env('ODDEN_USER_MODEL')`, `null` | See [the user model](#the-user-model). |
| `auto_associate_companies` | `false` | Run [domain auto-association](contacts-and-companies.md#domain-auto-association) on every `CreateContactAction` call. |
| `freemail_domains` | `[]` | Extra domains treated as freemail. |
| `lifecycle.strict_transitions` | `false` | Enforce the [transition graph](lifecycle-stages.md#strict-mode). |
| `rate_limits.public` | `env('ODDEN_PUBLIC_RATE_LIMIT', 30)` | Requests per minute per IP for `odden-public`. |
| `rate_limits.poll` | `env('ODDEN_POLL_RATE_LIMIT', 120)` | Requests per minute per IP for `odden-poll`. |
| `rate_limits.api` | `env('ODDEN_API_RATE_LIMIT', 600)` | Requests per minute per IP for `odden-api`. |
| `enrichment.driver` | `env('ODDEN_ENRICHMENT_DRIVER', 'heuristic')` | Default [enrichment](contacts-and-companies.md#enrichment) driver. |
| `enrichment.auto_enrich` | `false` | Enrich every company created through `CreateCompanyAction`. |

If you rename `tables.contacts`, note that the `has_downloaded_asset` and `has_attended_event` [list rules](lists.md#rules) refer to `odden_contacts.id` by name and stop working.
