---
title: Installation
description: Install the Odden packages, run the migrations, and schedule the commands each module needs.
---

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- SQLite 3.26+, MySQL 8.0+, or PostgreSQL 15+. Every package is tested against all three in CI.
- Filament 5.9 or newer, only if you install the [Filament admin](filament/index.md)

## Install the packages

Require the modules you want. Each one requires `getodden/crm-core`, so you don't need to list it unless you only want Core:

```bash
composer require getodden/crm-sales
```

Every package registers itself through Laravel's package discovery and loads its own migrations. Run them:

```bash
php artisan migrate
```

All Odden tables are prefixed with `odden_`. Table names are configurable per package (the `tables` key in each config file) if they would clash with your own.

## Paid add-ons

Odden Marketing, Odden Service, and Odden CRM Pro are private packages installed with a license key. See [Install a paid add-on](paid-add-ons.md).

## Add the Filament admin

```bash
composer require getodden/crm-filament
```

Then register the plugin in your panel provider. See [Filament admin](filament/index.md) for the details.

## Publish configuration (optional)

Each package works with its defaults. Publish a config file only when you need to change something:

```bash
php artisan vendor:publish --tag=odden-core-config
php artisan vendor:publish --tag=odden-sales-config
```

Migrations can be published the same way with the `odden-core-migrations` and `odden-sales-migrations` tags, if you want to modify them before they run.

## Schedule the commands

Several features run on a schedule: sequence steps, quote expiry, and more. Odden doesn't register a schedule for you, so add the commands for the modules you installed to `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

// Sales
Schedule::command('sales:process-cadences')->everyFifteenMinutes()->withoutOverlapping()->onOneServer();
Schedule::command('sales:expire-quotes')->dailyAt('01:00')->withoutOverlapping()->onOneServer();
```

These are the frequencies Odden Cloud uses. The paid Marketing and Service add-ons list their own commands in their documentation. `onOneServer()` needs a cache store shared by all your servers; drop it if you run a single server.

Then make sure the scheduler runs, with `php artisan schedule:work` locally or a cron entry for `php artisan schedule:run` in production.

## Configure mail

Configure a mailer in `config/mail.php` before you use any of the email features.

> **Run a queue worker.** From v0.3, Odden queues every email it sends. Nothing is delivered from the request or command that triggers it: each email is pushed to the queue and a queue worker sends it. Without a worker, mail stays on the queue and is never delivered. This covers:
>
> - Sales: [sequence email steps](sales/sequences.md#email-steps) and [meeting confirmations](sales/meeting-links.md#confirmation-emails)
> - The paid Marketing and Service add-ons queue their mail the same way

Run a worker in production, for example with Supervisor:

```bash
php artisan queue:work
```

By default each package uses your default queue connection, its default queue, and your default mailer. Each package can send its mail on its own connection and queue:

| Package | Config keys | Environment variables |
|---|---|---|
| Sales | `odden-sales.mail.mailer`, `.connection`, `.queue` | `ODDEN_SALES_MAILER`, `ODDEN_SALES_QUEUE_CONNECTION`, `ODDEN_SALES_MAIL_QUEUE` |

If you set a queue name, include it in your worker's `--queue` list, for example `php artisan queue:work --queue=sales-mail,default`. See [Sales `mail` configuration](sales/configuration.md#mail), which also sets the Sales "from" address.

With the `sync` queue connection the mail is sent during the request, which is fine for local development only.

## Next steps

- [Configuration](configuration.md): the user model, public routes, API tokens, and rate limits
- [Core concepts](core/index.md)
