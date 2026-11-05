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

To serve only some screens from Livewire, pass the keys as the third argument:

```php
LivewireScreens::routes('crm', ['web', 'auth'], only: ['contacts.index']);
```

With a list, only those keys get routes, so a host that serves both shells at the same paths opts in key by key. An empty list registers no screen routes. A key that is unknown, excepted, in a disabled module or not drawn by the Livewire shell throws `InvalidScreenRegistrationException` naming it.

A screen the registry excepts or whose module is disabled gets no route. For a tenant prefix such as `{workspace}/crm`, bind a `ScreenTenantParameters` that returns the `workspace` value, and set `URL::defaults(['workspace' => ...])` for the request, because the layout's navigation builds its links without a tenant.

## Styles

Tailwind 4 must scan the package views, and the `getodden/ui` theme must be imported:

```css
@import 'tailwindcss';
@import '../../vendor/getodden/ui/resources/css/theme.css';
@source '../../vendor/getodden/crm-livewire/resources/views';
```

## Layout

Screens use `odden-livewire::layouts.app`, a sidebar and top bar workspace layout with a skip link, a `main` landmark, grouped navigation that marks the current page with `aria-current="page"` and one toast region. It sets the `dark` class on `<html>` from `localStorage` key `theme` (`dark`, `light` or `system`).

Each region is a partial. Replace one by creating `resources/views/vendor/odden-livewire/layouts/partials/{name}.blade.php` in your application:

| Partial | Default |
| --- | --- |
| `head` | empty; put `@vite` here |
| `brand` | `config('app.name')` as text |
| `navigation` | the screens the user can open, grouped, with their icons |
| `header-actions` | the global search, when `search.enabled` and a user is signed in, and the notification bell for signed-in users (see Bell) |
| `user-menu` | the user's name and a sign-out form |
| `before-content` | empty; host banners |
| `body-end` | empty; host scripts |

The sign-out form posts to the route named by `logout_route` in `config/odden-livewire.php` (default `logout`) and is left out when that route does not exist. The route must resolve with no parameters, or with `URL::defaults`. The inline theme script carries `Vite::cspNonce()` when a nonce is set. Publish the file with `php artisan vendor:publish --tag=odden-livewire-config`.

To replace the whole layout, create `resources/views/vendor/odden-livewire/layouts/app.blade.php`.

## Bell

The default `header-actions` partial renders `<livewire:odden-livewire.notification-bell />` for signed-in users while `odden-livewire.bell.enabled` is true (the default). `odden-livewire.bell.limit` caps the list (default 20). The button's accessible name carries the unread count ("Notifications, 3 unread"), the count is polled every 30 seconds while the page is visible, and the panel is a `ui::modal` listing title, body, relative time, an Open link and "Mark as read", with "Mark all as read" when anything is unread.

What the bell shows comes from `Odden\Livewire\Contracts\BellFeed`:

```php
interface BellFeed
{
    public function visible(Authenticatable $user): bool;
    public function items(Authenticatable $user, int $limit): array;
    public function unreadCount(Authenticatable $user): int;
    public function find(Authenticatable $user, string $id): ?BellItem;
    public function markRead(Authenticatable $user, string $id): void;
    public function markAllRead(Authenticatable $user): void;
}
```

`items` returns `BellItem`s newest first. `find` must return `null` for any id that is not this user's: the bell redirects only to the `url` of the item `find` returns, never to a value from the browser. When `visible` is false (a read-only support session, say) the bell renders nothing and every method does nothing.

The default `DatabaseBellFeed` reads the user's Laravel database notifications (the user model must use `Notifiable`; the `notifications` table ids must be UUIDs, as Laravel creates them). The title is `data['title']`, the body `data['body']`, the link `data['actions'][0]['url']` or `data['url']` (the format Filament stores), and only `http(s)` and root-relative links are kept. Reading is scoped to the notifiable and writes go through `Odden\Core\Actions\MarkNotificationsReadAction`.

Bind your own feed to replace it:

```php
$this->app->bind(BellFeed::class, WorkspaceBellFeed::class);
```

The bell does not use `GuardsWrites`: marking your own notification read is personal state. A feed that must refuse it in some sessions returns `false` from `visible`. The feed is resolved on every call; keep no user in a static or a singleton.

## Read-only sessions

A host can make a request read-only by binding `Odden\Livewire\Contracts\WriteGuard`:

```php
$this->app->bind(WriteGuard::class, SupportSessionGuard::class);
```

`refusal(Authenticatable $user)` returns a message when the request may not change anything, and `null` otherwise. The default binding, `AllowAllWrites`, always returns `null`. The guard is resolved on every call; keep no user or answer in a static or a singleton.

Every component that uses `AuthorizesScreen` (and so `GuardsWrites`) is then default-deny: any action method is refused, with a danger `ui-toast` carrying the message, unless it carries `#[Odden\Livewire\Attributes\Reads]` or is one of the framework read methods (`$refresh`, `$set`, `$sync`, `$commit`, pagination, `sortBy` and `resetFilters` of `WithRecordTable`, `cancelConfirmation` of `WithConfirmation`). `runConfirmation` is refused because it runs the confirmed write. Property updates are not refused. An event listener is judged by its own method; a refused listener and a refused `#[Json]` method answer 403 instead of a toast, because Livewire runs listeners and JSON methods itself, so returning early cannot stop them. Livewire's lazy-loading calls are never refused. WithFileUploads' `_startUpload`, `_finishUpload`, `_uploadErrored` and `_removeUpload` are refused on purpose: a read-only session may not upload.

Only components that use `GuardsWrites` are guarded. Child components that are not screens (a bell, a search box, a modal) are not, unless they use `GuardsWrites` themselves.

## Global search

`<livewire:odden-livewire.global-search />` is a header button and a dialog (Cmd+K or Ctrl+K) that searches the configured sources and lists the hits as links under one heading per source. The default `header-actions` partial draws it for signed-in users; set `odden-livewire.search.enabled` to `false` to leave it out. Type at least 2 characters (at most 100 are used); the arrow keys move between the field and the results and Enter follows a link. The component only reads, so it does not use `GuardsWrites`, and a result count is announced through a polite live region.

Sources are class strings in `odden-livewire.search.sources`, resolved from the container on every request:

```php
'search' => [
    'enabled' => true,
    'sources' => [
        Odden\Livewire\Search\Sources\ContactSearch::class,
        Odden\Livewire\Search\Sources\CompanySearch::class,
    ],
],
```

A source implements `Odden\Livewire\Contracts\SearchSource`: `label()` is the group heading, `screenKey()` is the screen the user must be able to open for the source to be searched at all, and `search($term, $user, $limit)` returns up to `$limit` `Odden\Livewire\Search\SearchHit` objects (title, url, details). The component asks for 5 per source. A class that does not implement the contract throws.

`ContactSearch` and `CompanySearch` match through `EncryptedSearch`, never by comparing encrypted columns in SQL, so a user without the Identity data area finds nothing. Their queries carry the host's own scopes. A hit's URL comes from `Screens::url('contacts.view', $record)` (or `companies.view`), and a hit whose view screen the user cannot open on that record is dropped.
