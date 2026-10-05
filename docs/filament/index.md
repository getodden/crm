---
title: Filament admin panel
description: Install getodden/crm-filament and register OddenPlugin to get a Filament admin panel for every Odden module you have installed.
---

`getodden/crm-filament` is a [Filament](https://filamentphp.com) plugin that adds an admin interface for Odden. You register one plugin, `Odden\Filament\OddenPlugin`, on a Filament panel, and it adds the resources and pages for the Odden modules installed in your app.

## What the plugin adds

With only `getodden/crm-core` installed, the panel gets:

- Resources for contacts, companies, lists and custom property definitions.
- The **Executive Overview** dashboard and the **Data Quality** page for finding and merging duplicate contacts and companies.

Each additional module adds its own resources and pages:

| Package | Adds |
| --- | --- |
| [`getodden/crm-sales`](../sales/index.md) | Deals (with a pipeline board), pipelines, quotes, quotas, sales email templates, cadences, playbooks, meeting links, lead routing rules, and the Sales Cockpit. |
| Odden Marketing and Odden Service (paid add-ons) | Their screens (campaigns, tickets, cockpits and more), added to the panel by the add-on itself through [`Modules`](modules.md) |

Contact, company and deal forms also show the [custom properties](../core/custom-properties.md) you define for them.

See [Resources](resources.md) and [Cockpits, pages and widgets](pages.md) for the full list.

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- Filament 5.9 or later (`filament/filament: ^5.9`)
- `getodden/crm-core`, installed and migrated (see [Installation](../installation.md))

The Sales module is optional, and so are the paid Marketing and Service add-ons.

## Install the package

```bash
composer require getodden/crm-filament
```

The package's service provider, `Odden\Filament\FilamentServiceProvider`, is auto-discovered. All it does is register the package's Blade views under the `odden-filament` namespace. There is no config file and nothing to publish.

If your app doesn't have a Filament panel yet, create one with Filament's installer:

```bash
php artisan filament:install --panels
```

## Register the plugin

Add `OddenPlugin::make()` to the `plugins()` of your panel provider:

```php
<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\AccountWidget;
use Odden\Filament\OddenPlugin;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                'primary' => Color::Indigo,
            ])
            ->plugins([
                OddenPlugin::make(),
            ])
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

On Laravel 12, use `Illuminate\Foundation\Http\Middleware\VerifyCsrfToken` instead of `PreventRequestForgery`; Laravel 13 renamed it.

The plugin's ID is `odden`, so `$panel->hasPlugin('odden')` and `$panel->getPlugin('odden')` work as usual.

Sign in at `/admin` and you will find the Odden resources under the **CRM**, **Sales**, **Executive** and **Settings** navigation groups, plus **Service** and **Marketing** when those paid add-ons are installed. Contacts, for example, are at `/admin/contacts`.

### User model

Odden resolves the user model from `odden-core.user_model` (the `ODDEN_USER_MODEL` environment variable), falling back to `auth.providers.users.model`. Owner, assignee and agent fields across the panel display the user's `name` attribute, so your user model needs one.

Outside the `local` environment, Filament only lets users into a panel if your user model implements `Filament\Models\Contracts\FilamentUser`. See [Authorization](authorization.md).

## Next steps

- [Configuration and navigation](configuration.md): what you can configure, how modules are detected, and the navigation groups and URLs.
- [Resources](resources.md): every resource, its pages, relation managers and actions.
- [Cockpits, pages and widgets](pages.md): the dashboards and tools the plugin adds.
- [Custom properties in forms](custom-properties.md)
- [Authorization](authorization.md)
- [Customizing and extending](customizing.md)
