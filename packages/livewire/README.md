# Odden CRM Livewire

Livewire 4 screens for Odden CRM, built on `getodden/ui`. They draw keys from the screen registry in `getodden/crm-core`, side by side with the Filament panel.

Status: **first slice.** One read-only screen, the contact list (`contacts.index`).

## Install

```bash
composer require getodden/crm-livewire
```

Requires Laravel 13, as `getodden/ui` does.

Register the routes in `routes/web.php`:

```php
use Odden\Livewire\Screens\LivewireScreens;

LivewireScreens::routes('crm', ['web', 'auth']);
```

Each screen the Livewire shell draws gets a route named `odden-livewire.{key}`. A key without that route is still drawn by Filament, so installing the package changes nothing until you call `routes()`. Once the route exists, `Screens::url()` and the navigation point to it.

The routes need Laravel's `auth` middleware, and your application ships its own login. A guest is sent to the `login` route.

Livewire update requests re-run only persistent middleware, and `auth` is persistent by default. If your middleware sets tenant or request state that a screen reads, add it with `Livewire::addPersistentMiddleware([...])`.

Every component that draws a screen must use `Odden\Livewire\Concerns\AuthorizesScreen`, which checks the screen's permissions on every request and update. `routes()` refuses a component without it, including a replacement set with `Screens::replace()`, and also refuses a route name or path that is already taken.

A screen the registry excepts or whose module is disabled gets no route. For a tenant prefix such as `{workspace}/crm`, bind a `ScreenTenantParameters` that returns the `workspace` value, and set `URL::defaults(['workspace' => ...])` for the request, because the layout's navigation builds its links without a tenant.

## Styles

Tailwind 4 must scan the package views, and the `getodden/ui` theme must be imported:

```css
@import 'tailwindcss';
@import '../../vendor/getodden/ui/resources/css/theme.css';
@source '../../vendor/getodden/crm-livewire/resources/views';
```

## Layout

Screens use `odden-livewire::layouts.app`. Publish nothing: create `resources/views/vendor/odden-livewire/layouts/app.blade.php` in your application and it replaces the package layout.
