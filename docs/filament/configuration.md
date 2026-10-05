---
title: Configuration and navigation
description: How OddenPlugin decides what to register, the navigation groups it uses, and the URLs and route names of its resources and pages.
---

`OddenPlugin` has no config file and no environment variables. What it registers depends on which Odden packages are installed, plus the fluent options in [Customizing and extending](customizing.md#plugin-options) for turning modules off or swapping classes. You change the rest (the panel path, how navigation groups are ordered, colors and so on) on the Filament panel itself.

## Plugin API

`Odden\Filament\OddenPlugin` implements `Filament\Contracts\Plugin` and exposes only:

| Method | Description |
| --- | --- |
| `OddenPlugin::make(): static` | Resolves the plugin from the container. |
| `getId(): string` | Returns `'odden'`. |
| `register(Panel $panel): void` | Adds the resources and pages described below to the panel. |
| `boot(Panel $panel): void` | Does nothing. |

There are no methods for turning individual modules, resources or pages on or off. If you need a smaller panel, register the resources yourself instead of using the plugin. See [Customizing and extending](customizing.md#replacing-a-resource).

## How modules are detected

When the panel is registered, `OddenPlugin::register()` checks whether Sales is installed, and registers whatever the paid add-ons have added to [`Modules`](modules.md):

| Module | Detected by | Registers |
| --- | --- | --- |
| Core (always) | — | `ContactResource`, `CompanyResource`, `CrmListResource`, `PropertyDefinitionResource`; pages `ExecutiveOverview`, `DataQuality` |
| Sales | `class_exists(Odden\Sales\Models\Deal::class)` | `DealResource`, `PipelineResource`, `QuoteResource`, `SalesQuotaResource`, `SalesEmailTemplateResource`, `SalesSequenceResource`, `SalesPlaybookResource`, `SalesMeetingLinkResource`, `LeadRoutingRuleResource`; page `SalesCockpit` |
| Marketing and Service (paid add-ons) | They register themselves with [`Modules`](modules.md) when installed | Their own resources and pages, in the **Marketing** and **Service** groups |

Core and Sales resources live in `Odden\Filament\Resources` and pages in `Odden\Filament\Pages`. A paid add-on's screens live in its own namespace (for example `Odden\Marketing\Filament`).

The check only asks whether the package's code is autoloadable. It doesn't check that the module's service provider booted or that its migrations ran, so run each module's migrations as soon as you install it. Otherwise its pages fail with missing-table errors.

The same checks apply inside the core resources:

- The contact and company **Associated Deals** relation managers only appear when sales is installed.
- The contact **Sales Sequences & Cadences** relation manager only appears when sales is installed.
- Marketing adds its contact tabs and the company **Recalculate Intent** action through [`Modules`](modules.md), so they only appear when Marketing is installed.
- The contact **Playbook** and **Auto-Route** actions only appear when sales is installed.
- The Executive Overview shows zeros for the sales metrics when Sales is not installed, and shows the summary cards only of the paid add-ons that are installed.

## Navigation groups

The plugin uses plain string navigation groups:

| Group | Contents |
| --- | --- |
| `Executive` | Executive Overview |
| `CRM` | Contacts, Companies, Lists, Data Quality |
| `Sales` | Sales Cockpit, Deals, Quotes, Sales Quotas, Email Templates, Sales Cadences, Sales Playbooks, Meeting Links, Lead Routing Rules |
| `Settings` | Custom Properties, Pipelines |

The paid add-ons add their own groups (**Service** and **Marketing**).

### Ordering the groups

To control the order of the groups in the sidebar, list them on the panel with Filament's `navigationGroups()`. The labels must match exactly:

```php
use Odden\Filament\OddenPlugin;

return $panel
    // ...
    ->plugins([
        OddenPlugin::make(),
    ])
    ->navigationGroups([
        'Executive',
        'CRM',
        'Sales',
        'Service',
        'Marketing',
        'Settings',
    ]);
```

You can also pass `Filament\Navigation\NavigationGroup` objects with the same labels to make groups collapsible or give them icons. Item order within a group comes from each resource's or page's `$navigationSort`, which the plugin hard-codes. To change it, see [Customizing and extending](customizing.md).

## URLs and route names

Resource and page URLs sit under your panel's path. With a panel whose ID and path are both `admin`:

| Class | URL | Route name |
| --- | --- | --- |
| `ContactResource` | `/admin/contacts` | `filament.admin.resources.contacts.index` |
| `CompanyResource` | `/admin/companies` | `filament.admin.resources.companies.index` |
| `CrmListResource` | `/admin/crm-lists` | `filament.admin.resources.crm-lists.index` |
| `PropertyDefinitionResource` | `/admin/property-definitions` | `filament.admin.resources.property-definitions.index` |
| `DealResource` | `/admin/deals`, board at `/admin/deals/board` | `filament.admin.resources.deals.index`, `...deals.board` |
| `PipelineResource` | `/admin/pipelines` | `filament.admin.resources.pipelines.index` |
| `QuoteResource` | `/admin/quotes` | `filament.admin.resources.quotes.index` |
| `SalesQuotaResource` | `/admin/sales-quotas` | `filament.admin.resources.sales-quotas.index` |
| `SalesEmailTemplateResource` | `/admin/sales-email-templates` | `filament.admin.resources.sales-email-templates.index` |
| `SalesSequenceResource` | `/admin/sales-sequences` | `filament.admin.resources.sales-sequences.index` |
| `SalesPlaybookResource` | `/admin/sales-playbooks` | `filament.admin.resources.sales-playbooks.index` |
| `SalesMeetingLinkResource` | `/admin/sales-meeting-links` | `filament.admin.resources.sales-meeting-links.index` |
| `LeadRoutingRuleResource` | `/admin/lead-routing-rules` | `filament.admin.resources.lead-routing-rules.index` |

Resources also have `create`, `edit` and, where they exist, `view` routes (`/admin/contacts/create`, `/admin/contacts/{record}/edit`, `/admin/contacts/{record}`). [Resources](resources.md) lists which pages each resource has.

Pages use `filament.admin.pages.{slug}`:

| Page | URL |
| --- | --- |
| `ExecutiveOverview` | `/admin/executive-overview` |
| `DataQuality` | `/admin/data-quality` |
| `SalesCockpit` | `/admin/sales-cockpit` |

To build URLs in code, use Filament's helpers rather than hard-coding paths:

```php
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Resources\DealResource;

$board = DealResource::getUrl('board');
$deal = DealResource::getUrl('view', ['record' => $deal]);
$cockpit = SalesCockpit::getUrl();
```

## Using more than one panel

You can register `OddenPlugin::make()` on several panels. Each panel gets its own copy of the resources and pages under its own path and route prefix (`filament.{panel-id}.…`).
