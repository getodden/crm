---
title: Cockpits, pages and widgets
description: The dashboards, cockpits and tool pages OddenPlugin adds to your panel, what each one reads and writes, and the reusable pipeline forecast widget.
---

Besides resources, `OddenPlugin` registers a set of custom Filament pages in `Odden\Filament\Pages`. Each one is a Livewire component with its own Blade view in the `odden-filament::pages.*` namespace. Like the resources, a page is only registered when the module it needs is installed (see [How modules are detected](configuration.md#how-modules-are-detected)).

The URLs below assume a panel at `/admin`.

Each page is only available to users who pass `viewAny` on the resources whose data it shows, and its write actions check `update` (or `delete`) on the record they change. With no policies registered, every panel user can use every page. See [Authorization](authorization.md#custom-pages).

## Core pages

Always registered.

### Executive Overview

`ExecutiveOverview`, at `/admin/executive-overview` in the **Executive** group. Its title is "Executive RevOps Command Center".

This page is a read-only dashboard across all modules. It shows:

- Revenue: active pipeline, weighted forecast, closed-won revenue, quota attainment, pipeline coverage, win rate and average deal size.
- A lifecycle funnel and customer health metrics.
- Marketing KPIs and service KPIs.
- High-value deals, at-risk strategic accounts, and stalled deals.
- A rep leaderboard and the top campaigns by attributed revenue.

The timeframe switcher accepts `month`, `quarter` (the default), `year` and `all`. Metrics for modules that are not installed show as zero or empty.

### Data Quality

`DataQuality`, at `/admin/data-quality` in the **CRM** group.

This page finds duplicate contacts and companies with `Odden\Core\Actions\FindDuplicateContactsAction` and `FindDuplicateCompaniesAction`, and shows a data cleanliness score. Each duplicate pair can be merged from the page with `MergeContactsAction` or `MergeCompaniesAction`. A merge moves the secondary record's activities, associations, deals and tickets (or contacts, for companies) onto the primary record, merges custom properties, and soft-deletes the secondary.

## Sales pages

Registered when `getodden/crm-sales` is installed.

### Sales Cockpit

`SalesCockpit`, at `/admin/sales-cockpit`. It is the first item in the **Sales** group, and its title is "Sales Prospecting Workspace".

This is a rep workspace. It shows the selected rep's tasks (filterable by timeframe), sequence activity, a day-by-day schedule, guided next actions, and a deal list with `all`, `closing` and `prospecting` tabs. A user selector switches the rep being viewed.

These actions write data:

- **Advance step** calls `SalesSequenceEnrollment::advanceStep()`.
- **Complete** marks an activity completed.
- **Quick touches**, the **call log** modal and the **meeting log** modal each create an activity on the contact. The call log can also create a follow-up task.
- **Start** on guided actions acts on the first item in the queue. It advances a sequence step, opens the call modal for the contact (nothing is logged until you save the call), or redirects, depending on the item.

## Add-on pages

Odden Marketing and Odden Service add their own cockpits and pages through [`Odden\Filament\Support\Modules`](modules.md); they are documented with the add-on.

## Pipeline forecast widget

`Odden\Filament\Widgets\DealPipelineForecastWidget` is a stats overview widget. It shows four stats from `Odden\Sales\Actions\CalculatePipelineForecastAction`: **Open Pipeline**, **Weighted Forecast**, **Closed Won** and **Win Rate**, with average deal size.

It appears at the top of the deals list page. The plugin doesn't add it to your dashboard, but you can register it on your panel yourself (this requires `getodden/crm-sales`):

```php
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Odden\Filament\OddenPlugin;
use Odden\Filament\Widgets\DealPipelineForecastWidget;

return $panel
    // ...
    ->plugins([
        OddenPlugin::make(),
    ])
    ->pages([
        Dashboard::class,
    ])
    ->widgets([
        AccountWidget::class,
        DealPipelineForecastWidget::class,
    ]);
```

The widget has a public `?int $pipelineId` property, which defaults to `null`. That value is passed to `CalculatePipelineForecastAction::execute()`. Money values are formatted with a hard-coded `$` sign.
