# Odden CRM Livewire

Livewire 4 screens for Odden CRM, built on `getodden/ui`. They draw keys from the screen registry in `getodden/crm-core`, side by side with the Filament panel.

Status: **0.2, first slice.** One read-only screen, the contact list (`contacts.index`).

## Install

```bash
composer require getodden/crm-livewire
```

Register the routes in `routes/web.php`:

```php
use Odden\Livewire\Screens\LivewireScreens;

LivewireScreens::routes('crm', ['web', 'auth']);
```

Each screen the Livewire shell draws gets a route named `odden-livewire.{key}`. A key without that route is still drawn by Filament, so installing the package changes nothing until you call `routes()`. Once the route exists, `Screens::url()` and the navigation point to it.

The routes need Laravel's `auth` middleware, and your application ships its own login. A guest is sent to the `login` route.

A screen the registry excepts or whose module is disabled gets no route. For a tenant prefix such as `{workspace}/crm`, bind a `ScreenTenantParameters` that returns the `workspace` value.

## Styles

Tailwind 4 must scan the package views, and the `getodden/ui` theme must be imported:

```css
@import 'tailwindcss';
@import '../../vendor/getodden/ui/resources/css/theme.css';
@source '../../vendor/getodden/crm-livewire/resources/views';
```

## Layout

Screens use `odden-livewire::layouts.app`. Publish nothing: create `resources/views/vendor/odden-livewire/layouts/app.blade.php` in your application and it replaces the package layout.
