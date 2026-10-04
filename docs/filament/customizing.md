---
title: Customizing and extending
description: Add your own resources next to Odden's, replace an Odden resource with a subclass, override the plugin's Blade views, and make sure the custom pages are styled.
---

`OddenPlugin` has fluent options to turn modules off, leave out individual resources and pages, and swap in your own subclasses (see [Plugin options](#plugin-options)). You can also add your own Filament classes next to the plugin.

## Plugin options

```php
use App\Filament\Resources\ContactResource as AppContactResource;
use Odden\Filament\OddenPlugin;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\LeadRoutingRuleResource;

return $panel
    // ...
    ->plugins([
        OddenPlugin::make()
            ->disableModules('marketing')
            ->except(LeadRoutingRuleResource::class, DataQuality::class)
            ->replace(ContactResource::class, AppContactResource::class),
    ]);
```

| Method | Effect |
| --- | --- |
| `disableModules(string ...$modules)` | Leaves out every resource and page of `sales`, `service` or `marketing`. Any other name throws `InvalidArgumentException`. Core's resources and pages are always registered. |
| `except(string ...$classes)` | Leaves out specific resources or pages. |
| `replace(string $original, string $replacement)` | Registers your class in place of an Odden resource or page. |

A module whose package isn't installed is skipped either way. The options apply when the plugin registers, so they work for pages as well as resources. Leaving a resource out removes its routes and navigation, and other Odden pages may still link to it (for example the deal view links to quotes), so disable a whole module rather than a resource the rest of the module depends on.

A replacement should be a subclass that keeps the resource's slug, as described below.

## Adding your own resources and pages

The plugin only adds to the panel, so your own resources, pages and widgets work alongside it as usual:

```php
use Filament\Pages\Dashboard;
use Odden\Filament\OddenPlugin;

return $panel
    // ...
    ->plugins([
        OddenPlugin::make(),
    ])
    ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
    ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
    ->pages([
        Dashboard::class,
    ]);
```

Your resources can use Odden models directly, for example a resource for `Odden\Core\Models\Activity`. Avoid slugs the plugin already uses (`contacts`, `companies`, `deals` and so on, listed in [URLs and route names](configuration.md#urls-and-route-names)). Filament derives a resource's slug from its class name, so `App\Filament\Resources\ContactResource` would also get `contacts` and clash with the plugin's routes.

To put your items into Odden's navigation groups, use the same group labels: `CRM`, `Sales`, `Service`, `Marketing`, `Executive` or `Settings`.

## Replacing a resource

To change an Odden resource, subclass it and register the subclass with `OddenPlugin::replace()`. If you want full control instead, skip the plugin on that panel and register the resources and pages you want yourself.

Start with the resource subclass. Filament derives the slug from the class name, so naming it `ContactResource` keeps the `contacts` URLs and route names the other Odden resources link to. With a different class name, set `protected static ?string $slug = 'contacts';`.

```php
<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ContactResource\Pages\CreateContact;
use App\Filament\Resources\ContactResource\Pages\EditContact;
use App\Filament\Resources\ContactResource\Pages\ListContacts;
use App\Filament\Resources\ContactResource\Pages\ViewContact;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Filament\Resources\ContactResource as OddenContactResource;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class ContactResource extends OddenContactResource
{
    protected static UnitEnum|string|null $navigationGroup = 'People';

    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->pushColumns([
                TextColumn::make('owner.name')->label('Owner'),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('owner_id', auth()->id());
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'view' => ViewContact::route('/{record}'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }
}
```

Override `getPages()` and subclass the pages as well. Each Odden page class sets `protected static string $resource` to the Odden resource, and Filament pages call their own resource's `table()`, `form()` and `getEloquentQuery()`. If your resource only overrides navigation properties (`$navigationGroup`, `$navigationLabel`, `$navigationSort`, `$navigationIcon`), the original pages are enough. But a `table()`, `form()` or `getEloquentQuery()` override has no effect unless the pages point at your class:

```php
<?php

namespace App\Filament\Resources\ContactResource\Pages;

use App\Filament\Resources\ContactResource;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts as OddenListContacts;

class ListContacts extends OddenListContacts
{
    protected static string $resource = ContactResource::class;
}
```

Create `CreateContact`, `EditContact` and `ViewContact` the same way, each extending the matching class in `Odden\Filament\Resources\ContactResource\Pages`. With all four pages pointing at your resource, the `getEloquentQuery()` scope above applies to the table and to the view and edit URLs. A contact owned by someone else then returns a 404.

Then register the subclass with `OddenPlugin::make()->replace(ContactResource::class, App\Filament\Resources\ContactResource::class)`. If you'd rather not use the plugin, register everything on the panel yourself. This example rebuilds the core part of the plugin:

```php
use App\Filament\Resources\ContactResource;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Pages\ExecutiveOverview;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Resources\CrmListResource;
use Odden\Filament\Resources\PropertyDefinitionResource;

return $panel
    // ...
    ->resources([
        ContactResource::class,
        CompanyResource::class,
        CrmListResource::class,
        PropertyDefinitionResource::class,
    ])
    ->pages([
        ExecutiveOverview::class,
        DataQuality::class,
    ]);
```

Add the sales, service and marketing classes the same way. [How modules are detected](configuration.md#how-modules-are-detected) lists what the plugin registers for each module. When you register them yourself, the `class_exists()` checks are up to you.

## Swapping a built-in behaviour

Four actions the panel uses are behind contracts, so an application (or an add-on package) can replace what they do without touching the panel. Bind the contract in a service provider; the panel asks the container for it. The built-in implementation stays the default until you do.

| Contract | Used by | Built-in implementation |
| --- | --- | --- |
| `Odden\Core\Contracts\SummarizesTimeline` | **AI Briefing** on contacts and companies | `SummarizeTimelineAction`: rule-based, no external call. |
| `Odden\Marketing\Contracts\SuggestsSubjectLines` | **AI Copy Assistant** on campaigns, and the subject-line helper on templates | `SuggestSubjectLinesAction`: fixed phrase templates, no AI model. |
| `Odden\Service\Contracts\DraftsTicketReply` | **Draft a reply** in a ticket's reply box | `DraftTicketReplyAction`: the best matching canned response plus matching help articles, no AI model. |
| `Odden\Marketing\Contracts\PublishesAdAudience` | **Sync Now** on an ad audience sync | `SyncAdAudienceAction`: computes SHA-256 hashes and records the count; does not contact an ad platform. |

```php
use Odden\Core\Contracts\SummarizesTimeline;

// In a service provider's register() method.
$this->app->bind(SummarizesTimeline::class, App\Support\MyTimelineSummarizer::class);
```

A replacement has to return the shape the contract documents; the panel reads those keys. Some of the contracts leave room:

- `DraftsTicketReply` only fills the reply box. The agent reads it, edits it and posts it; nothing is sent from the contract.

- `PublishesAdAudience` may return a `message`, which **Sync Now** shows instead of its default wording (for example "Uploaded 1,204 members to Meta."). `hashed_emails` and `hashed_domains` are optional, since an implementation that uploads has no reason to hand every hash back.
- Nothing requires a replacement to extend the built-in class. Implement the interface.

The default is registered with `bindIf`, so your binding wins whatever order the providers load in.

## Overriding views

The plugin's custom pages and modals render Blade views from the `odden-filament` namespace:

| View | Used by |
| --- | --- |
| `odden-filament::pages.executive-overview` | `ExecutiveOverview` |
| `odden-filament::pages.data-quality` | `DataQuality` |
| `odden-filament::pages.sales-cockpit` | `SalesCockpit` |
| `odden-filament::pages.deal-kanban` | `DealResource` board page |
| `odden-filament::pages.service-cockpit` | `ServiceCockpit` |
| `odden-filament::pages.service-analytics` | `ServiceAnalytics` |
| `odden-filament::pages.ticket-kanban` | `TicketResource` board page |
| `odden-filament::pages.marketing-cockpit` | `MarketingCockpit` |
| `odden-filament::pages.abm-cockpit` | `AbmCockpit` |
| `odden-filament::pages.marketing-attribution` | `MarketingAttribution` |
| `odden-filament::pages.campaign-benchmarking` | `CampaignBenchmarking` |
| `odden-filament::pages.marketing-calendar` | `MarketingCalendar` |
| `odden-filament::pages.utm-link-builder` | `UtmLinkBuilder` |
| `odden-filament::pages.sender-domain-health` | `SenderDomainHealth` |
| `odden-filament::components.ai-briefing-modal` | **AI Briefing** action on contacts and companies |

The package doesn't register any publishable files, so `vendor:publish` has nothing to copy. Laravel still checks your app's `resources/views/vendor/odden-filament` directory first, so you can override a view by copying it there under the same relative path:

```bash
mkdir -p resources/views/vendor/odden-filament/pages
cp vendor/getodden/crm-filament/resources/views/pages/sales-cockpit.blade.php \
   resources/views/vendor/odden-filament/pages/sales-cockpit.blade.php
```

Laravel only picks up the override directory if it exists when the application boots. The views call public properties and methods on the page classes (for example `$this->guidedActions` or `wire:click="advanceEnrollment(...)"`), so check your copy whenever you upgrade the package.

Some actions also render views from the module packages, such as `odden-marketing::template-preview`. Override it in `resources/views/vendor/odden-marketing` in the same way. The deal health score modal is `odden-filament::deals.health-score-modal`, so override it in `resources/views/vendor/odden-filament/deals`.

## Styling the custom pages

Most of the custom pages (the cockpits, Executive Overview, Data Quality, Service Analytics and the two boards) carry most of their styling in a `<style>` block inside the view, with only a few Tailwind utility classes.

The Campaign Calendar, Campaign Benchmarking, Attribution & ROI, UTM Link Builder and Domain Health pages are styled almost entirely with Tailwind utility classes. Filament's default stylesheet only contains the classes Filament itself uses, so for all of these pages to look as intended, create a custom Filament theme and add the package's views to its sources. Filament's theme command creates `resources/css/filament/{panel-id}/theme.css` and walks you through registering it:

```bash
php artisan make:filament-theme
```

Then add this line to `theme.css`, next to the `@source` lines Filament generated:

```css
@source '../../../../vendor/getodden/crm-filament/resources/views/**/*';
```

The path is relative to `theme.css`. Rebuild your assets afterwards.
