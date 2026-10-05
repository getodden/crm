---
title: Add-on modules
description: How a separate package adds its own resources, pages and screens to the Odden Filament admin.
---

The Odden admin is built from modules. Core and Sales are built in, and the paid **Odden Marketing** and **Odden Service** add-ons add theirs through a small registry, `Odden\Filament\Support\Modules`. The same registry is open to your own packages, so a module you write can add screens to the panel without changing any Odden code.

## Registering a module

Register from your package's service provider, in `register()` (not `boot()`), so the module is known before any panel is built:

```php
use Odden\Filament\Support\Modules;

public function register(): void
{
    if (! class_exists(Modules::class)) {
        return; // the Odden admin isn't installed
    }

    Modules::register(
        'billing',
        resources: [InvoiceResource::class],
        pages: [RevenueCockpit::class],
    );
}
```

Only class names and closures are stored, so registering costs nothing when the panel is never used. Registering the same module name again replaces the earlier entry, so booting the application twice doesn't list your screens twice.

## What a module can add

| Argument | Adds |
| --- | --- |
| `resources` | Resources, registered on the panel unless the module is disabled. |
| `pages` | Pages, registered on the panel unless the module is disabled. |
| `contactRelationManagers` | Relation managers (tabs) on the contact screen. |
| `companyActions` | Row actions on the company table. Each entry is a closure that returns a Filament `Action`. |
| `executiveCards` | Summary cards on the Executive Overview. Each entry has a `view`, a `data` closure that returns the view's data, and a `position` (`before` or `after` the Sales card; `after` by default). |
| `authorizationResources` | Resources whose permissions decide who can open the Executive Overview, in addition to the built-in ones. |

The plugin's options apply to a module's screens like any other: `disableModules('billing')` leaves out its resources and pages, `except()` leaves out one class, and `replace()` swaps one for a subclass. See [Customizing](customizing.md). Disabling a module doesn't remove the contact tabs, company actions or Executive Overview cards it added; leave out the add-on's package if you don't want them.

## Keeping the package optional

A module that works without the admin should keep its Filament code apart from its domain code (for example in a `filament/` folder with its own namespace), and register only when `Modules` exists, as above. Views can live under a namespace of their own, loaded with `loadViewsFrom()` in the same provider.
