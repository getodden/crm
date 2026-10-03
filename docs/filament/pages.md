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
- **Start** on guided actions acts on the first item in the queue. It advances a sequence step, logs a call touch on the contact, or redirects, depending on the item.

## Service pages

Registered when `getodden/crm-service` is installed.

### Support Cockpit

`ServiceCockpit`, at `/admin/service-cockpit`. It is labelled **Support Cockpit** and is the first item in the **Service** group. Its title is "Support Agent Workspace".

This is an agent workspace. It shows open, unassigned, my-active and SLA-at-risk counts, plus the average CSAT. Its tabs are `triage`, `my_tickets`, `sla_watch` and `all`, with search, priority and source filters.

These actions write data:

- **Claim** sets the ticket's owner to the current user and moves `new` tickets to `open`.
- **Quick reply** adds an agent message or internal note with `ReplyTicketAction` and can set the status to `open`, `waiting_on_customer` or `resolved`. You can insert canned responses and suggested knowledge-base articles (from `DeflectTicketAction`).
- **Quick resolve** runs `ResolveTicketAction` with an optional note.

Both use the same actions as the ticket resource, so a public reply emails the customer `TicketRepliedNotification` and a resolve emails `TicketResolvedCsatNotification` (when the ticket's contact has an email address), and both are logged on the contact's timeline. Internal notes send nothing. Setting a reply's status to `resolved` runs the resolve action too.

### Service Analytics

`ServiceAnalytics`, at `/admin/service-analytics` in the **Service** group.

A read-only report of ticket volume, resolution rate, first response time, mean time to resolution, SLA compliance and breaches, CSAT distribution, channel and priority breakdowns, and per-agent performance. Date ranges are `7_days`, `30_days` (the default), `this_month` and `all_time`.

## Marketing pages

Registered when `getodden/crm-marketing` is installed. All are in the **Marketing** group.

| Page | URL | What it does |
| --- | --- | --- |
| `MarketingCockpit` (Marketing Cockpit) | `/admin/marketing-cockpit` | Campaign, delivery, open/click, lead and workflow totals, recent campaigns, active forms and submissions, closed-loop metrics (`CalculateClosedLoopMetricsAction`) and a conversion funnel (`AnalyzeConversionFunnelAction`). It can send a campaign immediately with `DispatchCampaignAction`. |
| `AbmCockpit` (ABM Cockpit) | `/admin/abm-cockpit` | Target accounts by tier, with intent scores and buying-committee counts. **Recalculate** runs `CalculateCompanyIntentScoreAction` for one company, or for every company that has an `account_tier` or an intent surge. |
| `MarketingAttribution` (Attribution & ROI) | `/admin/marketing-attribution` | Campaign ROI, attributed pipeline and won revenue, leads and blended cost per lead, under a selectable attribution model: `first_touch` (default), `last_touch`, `linear`, `u_shaped`, `w_shaped` or `time_decay`. |
| `CampaignBenchmarking` (Campaign Benchmarking) | `/admin/campaign-benchmarking` | Side-by-side comparison of selected campaigns against averages. It starts with the four most recently sent campaigns. |
| `MarketingCalendar` (Campaign Calendar) | `/admin/marketing-calendar` | A month view of campaign sends, with previous, next and current month navigation. |
| `UtmLinkBuilder` (UTM Link Builder) | `/admin/utm-link-builder` | Builds a tracked URL from a base URL (it defaults to `url('/')`, or to a landing page or campaign you select) and UTM source, medium, campaign, term and content. Nothing is saved. |
| `SenderDomainHealth` (Domain Health (SPF/DKIM)) | `/admin/sender-domain-health` | Checks SPF, DKIM, DMARC and MX for a domain with `DomainHealthCheckService`. The domain defaults to the domain of `odden-marketing.defaults.sender_email`, and the DKIM selector defaults to `odden`. |

> `SenderDomainHealth` makes live DNS lookups (`dns_get_record()`) each time it renders.

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
