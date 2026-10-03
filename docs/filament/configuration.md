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

When the panel is registered, `OddenPlugin::register()` checks whether one model class from each optional module exists:

| Module | Detected by | Registers |
| --- | --- | --- |
| Core (always) | — | `ContactResource`, `CompanyResource`, `CrmListResource`, `PropertyDefinitionResource`; pages `ExecutiveOverview`, `DataQuality` |
| Sales | `class_exists(Odden\Sales\Models\Deal::class)` | `DealResource`, `PipelineResource`, `QuoteResource`, `SalesQuotaResource`, `SalesEmailTemplateResource`, `SalesSequenceResource`, `SalesPlaybookResource`, `SalesMeetingLinkResource`, `LeadRoutingRuleResource`; page `SalesCockpit` |
| Service | `class_exists(Odden\Service\Models\Ticket::class)` | `TicketResource`, `SlaPolicyResource`, `KnowledgeArticleResource`, `CannedResponseResource`, `TicketRoutingRuleResource`; pages `ServiceCockpit`, `ServiceAnalytics` |
| Marketing | `class_exists(Odden\Marketing\Models\Campaign::class)` | `CampaignResource`, `MarketingTemplateResource`, `MarketingFormResource`, `LandingPageResource`, `MarketingWorkflowResource`, `LeadScoringRuleResource`, `MarketingSubscriptionResource`, `NpsSurveyResource`, `MarketingAssetResource`, `MarketingEventResource`, `AdAudienceSyncResource`; pages `MarketingCockpit`, `AbmCockpit`, `MarketingAttribution`, `CampaignBenchmarking`, `MarketingCalendar`, `UtmLinkBuilder`, `SenderDomainHealth` |

Resources live in `Odden\Filament\Resources` and pages in `Odden\Filament\Pages`.

The check only asks whether the package's code is autoloadable. It doesn't check that the module's service provider booted or that its migrations ran, so run each module's migrations as soon as you install it. Otherwise its pages fail with missing-table errors.

The same checks apply inside the core resources:

- The contact and company **Associated Deals** relation managers only appear when sales is installed.
- The contact **Sales Sequences & Cadences** relation manager only appears when sales is installed.
- The contact **Marketing Campaigns & Email Touchpoints**, **Lead Capture & Form Submissions** and **Lead Score & Decay History** relation managers only appear when marketing is installed.
- The contact **Playbook** and **Auto-Route** actions only appear when sales is installed.
- The company **Recalculate Intent** action only appears when marketing is installed.
- The Executive Overview shows zeros for the sales, service and marketing metrics of modules that are not installed.

## Navigation groups

The plugin uses plain string navigation groups:

| Group | Contents |
| --- | --- |
| `Executive` | Executive Overview |
| `CRM` | Contacts, Companies, Lists, Data Quality |
| `Sales` | Sales Cockpit, Deals, Quotes, Sales Quotas, Email Templates, Sales Cadences, Sales Playbooks, Meeting Links, Lead Routing Rules |
| `Service` | Support Cockpit, Tickets, SLA Policies, Knowledge Base, Canned Responses, Ticket Routing Rules, Service Analytics |
| `Marketing` | Marketing Cockpit, ABM Cockpit, Attribution & ROI, Campaigns, Campaign Benchmarking, Email Templates, Campaign Calendar, Lead Capture Forms, Marketing Workflows, Landing Pages, Lead Scoring Rules, Nps Surveys, Suppression List, Marketing Assets, Marketing Events, Ad Audience Sync Bridges, UTM Link Builder, Domain Health (SPF/DKIM) |
| `Settings` | Custom Properties, Pipelines |

Sales and Marketing each have an item labelled **Email Templates**. The Sales one manages `SalesEmailTemplate` records used by cadences, and the Marketing one manages `MarketingTemplate` records used by campaigns.

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
| `TicketResource` | `/admin/tickets`, board at `/admin/tickets/board` | `filament.admin.resources.tickets.index`, `...tickets.board` |
| `SlaPolicyResource` | `/admin/sla-policies` | `filament.admin.resources.sla-policies.index` |
| `KnowledgeArticleResource` | `/admin/knowledge-articles` | `filament.admin.resources.knowledge-articles.index` |
| `CannedResponseResource` | `/admin/canned-responses` | `filament.admin.resources.canned-responses.index` |
| `TicketRoutingRuleResource` | `/admin/ticket-routing-rules` | `filament.admin.resources.ticket-routing-rules.index` |
| `CampaignResource` | `/admin/campaigns` | `filament.admin.resources.campaigns.index` |
| `MarketingTemplateResource` | `/admin/marketing-templates` | `filament.admin.resources.marketing-templates.index` |
| `MarketingFormResource` | `/admin/marketing-forms` | `filament.admin.resources.marketing-forms.index` |
| `LandingPageResource` | `/admin/landing-pages` | `filament.admin.resources.landing-pages.index` |
| `MarketingWorkflowResource` | `/admin/marketing-workflows` | `filament.admin.resources.marketing-workflows.index` |
| `LeadScoringRuleResource` | `/admin/lead-scoring-rules` | `filament.admin.resources.lead-scoring-rules.index` |
| `MarketingSubscriptionResource` | `/admin/marketing-subscriptions` | `filament.admin.resources.marketing-subscriptions.index` |
| `NpsSurveyResource` | `/admin/nps-surveys` | `filament.admin.resources.nps-surveys.index` |
| `MarketingAssetResource` | `/admin/marketing-assets` | `filament.admin.resources.marketing-assets.index` |
| `MarketingEventResource` | `/admin/marketing-events` | `filament.admin.resources.marketing-events.index` |
| `AdAudienceSyncResource` | `/admin/ad-audience-syncs` | `filament.admin.resources.ad-audience-syncs.index` |

Resources also have `create`, `edit` and, where they exist, `view` routes (`/admin/contacts/create`, `/admin/contacts/{record}/edit`, `/admin/contacts/{record}`). [Resources](resources.md) lists which pages each resource has.

Pages use `filament.admin.pages.{slug}`:

| Page | URL |
| --- | --- |
| `ExecutiveOverview` | `/admin/executive-overview` |
| `DataQuality` | `/admin/data-quality` |
| `SalesCockpit` | `/admin/sales-cockpit` |
| `ServiceCockpit` | `/admin/service-cockpit` |
| `ServiceAnalytics` | `/admin/service-analytics` |
| `MarketingCockpit` | `/admin/marketing-cockpit` |
| `AbmCockpit` | `/admin/abm-cockpit` |
| `MarketingAttribution` | `/admin/marketing-attribution` |
| `CampaignBenchmarking` | `/admin/campaign-benchmarking` |
| `MarketingCalendar` | `/admin/marketing-calendar` |
| `UtmLinkBuilder` | `/admin/utm-link-builder` |
| `SenderDomainHealth` | `/admin/sender-domain-health` |

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
