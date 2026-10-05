---
title: Resources
description: Reference for every Filament resource OddenPlugin registers, with its model, pages, relation managers and custom actions.
---

This page lists the resources `OddenPlugin` registers, grouped by the module that provides them. All classes are in the `Odden\Filament\Resources` namespace. Every resource has the standard Filament create, edit and delete actions, plus a bulk delete action, unless noted otherwise. Labels are given as they appear in the panel.

Resources for a module are only registered when that module is installed. See [How modules are detected](configuration.md#how-modules-are-detected).

The custom actions listed here check your model policies when you register them. See [Resource actions](authorization.md#resource-actions) for the ability each one needs.

## Core

| Resource | Model | Navigation | Pages |
| --- | --- | --- | --- |
| `ContactResource` | `Odden\Core\Models\Contact` | CRM › Contacts | list, create, view, edit |
| `CompanyResource` | `Odden\Core\Models\Company` | CRM › Companies | list, create, view, edit |
| `CrmListResource` | `Odden\Core\Models\CrmList` | CRM › Lists | list, create, view, edit |
| `PropertyDefinitionResource` | `Odden\Core\Models\PropertyDefinition` | Settings › Custom Properties | list, create, edit |

### Contacts

The contact form has **Contact Details** (name, job title, email, phone, LinkedIn URL, lifecycle stage, lead status, owner), **Marketing & Lead Qualification** (lead score, email verified date, last marketing email), and a **Custom Properties** section when contact properties are defined (see [Custom properties in forms](custom-properties.md)).

The table filters on lead status, lifecycle stage and trashed records. Contacts are soft-deleted, so you also get bulk restore and force-delete.

Row actions:

| Action | What it does |
| --- | --- |
| **Playbook** | Pick an active sales playbook, answer its questions, and record the answers with `Odden\Sales\Actions\ExecuteSalesPlaybookAction`. Requires `getodden/crm-sales`. |
| **Auto-Route** | Runs `Odden\Sales\Actions\RouteLeadAction` to assign an owner using the active lead routing rules. Requires `getodden/crm-sales`. |
| **AI Briefing** | Shows the briefing from the bound `Odden\Core\Contracts\SummarizesTimeline` (by default the rule-based `SummarizeTimelineAction`) in a modal. |
| **Merge** | Merges a selected duplicate into this contact with `Odden\Core\Actions\MergeContactsAction`: its activities, associations, deals and tickets move to this contact, custom properties are merged, and the duplicate is soft-deleted. |

Relation managers: Companies (attach/detach), Associated Deals (sales), Sales Sequences & Cadences (sales; enroll, advance step, unenroll), Marketing Campaigns & Email Touchpoints (marketing), Lead Capture & Form Submissions (marketing), Lead Score & Decay History (marketing; includes an **Adjust Score** action), Activities (create, edit, delete, complete), and Property History / Audit Trail.

### Companies

The company form includes a **Custom Properties** section for company properties. Like contacts, companies are soft-deleted and the table has a trashed filter with bulk restore and force-delete.

Row actions: **Recalculate Health** (`Odden\Core\Actions\CalculateCustomerHealthScoreAction`), **Recalculate Intent** (`Odden\Marketing\Actions\CalculateCompanyIntentScoreAction`, only shown when marketing is installed), **AI Briefing**, and **Merge** (`Odden\Core\Actions\MergeCompaniesAction`).

Relation managers: Contacts (attach/detach), Associated Deals (sales), Activities, and Property History / Audit Trail.

### Lists

Lists are static or active. Active lists have a **Sync** row action, and a **Sync Members** action on the view page, which call `CrmList::syncActiveMembers()`. The view page has a **List Members** relation manager.

### Custom properties

`PropertyDefinitionResource` manages `PropertyDefinition` records: entity type, internal key (lowercase letters, digits and underscores), label, data type, group, description, select options, and the required and searchable flags. See [Custom properties in forms](custom-properties.md) for how these appear on forms.

## Sales

Registered when `getodden/crm-sales` is installed. See the [sales module docs](../sales/index.md) for the underlying models and actions.

| Resource | Model | Navigation | Pages |
| --- | --- | --- | --- |
| `DealResource` | `Odden\Sales\Models\Deal` | Sales › Deals | list, board, create, view, edit |
| `QuoteResource` | `Odden\Sales\Models\Quote` | Sales › Quotes | list, create, view, edit |
| `SalesQuotaResource` | `Odden\Sales\Models\SalesQuota` | Sales › Sales Quotas | list, create, edit |
| `SalesEmailTemplateResource` | `Odden\Sales\Models\SalesEmailTemplate` | Sales › Email Templates | list, create, edit |
| `SalesSequenceResource` | `Odden\Sales\Models\SalesSequence` | Sales › Sales Cadences | list, create, edit |
| `SalesPlaybookResource` | `Odden\Sales\Models\SalesPlaybook` | Sales › Sales Playbooks | list, create, edit |
| `SalesMeetingLinkResource` | `Odden\Sales\Models\SalesMeetingLink` | Sales › Meeting Links | list, create, edit |
| `LeadRoutingRuleResource` | `Odden\Sales\Models\LeadRoutingRule` | Sales › Lead Routing Rules | list, create, edit |
| `PipelineResource` | `Odden\Sales\Models\Pipeline` | Settings › Pipelines | list, create, edit |

### Deals

- **List page:** the `DealPipelineForecastWidget` stats header (open pipeline, weighted forecast, closed won, win rate) and a **Pipeline Board** button. Row actions are **Playbook** and **Auto-Route** (the same actions as on contacts, run against the deal). Deals are soft-deleted, so the table has a trashed filter with bulk restore and force-delete.
- **Board page** (`/deals/board`): a kanban of the selected pipeline's stages. It starts on the default pipeline, or the first pipeline if none is the default. Dragging a card calls `Deal::moveToStage()` with the current user. A card dropped on a stage of a different pipeline isn't moved, and an error notification is shown.
- **Create page:** sets `owner_id` to the current user if left empty, and writes an initial `DealStageHistory` row.
- **View page:** a deal health button (its label and color come from `Deal::getHealthScore()`) that opens the health analysis, **Generate Quote** (`GenerateQuoteFromDealAction`), **Run Playbook**, **Mark Won** (`Deal::markWon()`), **Mark Lost** (`Deal::markLost()`, with a required `LostReason` select) and Edit.
- **Form:** includes a **Custom Properties** section for `deal` properties.

Relation managers: Associated Contacts, Associated Companies (attach/detach), Products & Line Items, Quotes & Proposals (with **Generate from Products**, **Portal** and **Accept & Sign**), Stage Movement History, Activities, and Property History / Audit Trail.

### Other sales resources

| Resource | Extra actions |
| --- | --- |
| `QuoteResource` | **Portal** opens the public quote page (route `odden.quotes.show`). **Accept & Sign** asks for a signer name and email and runs `AcceptQuoteAction::accept()`, the same rules as the public page: it refuses accepted, declined and expired quotes, and accepts the quote and closes the deal as won in one transaction. If that fails (for example a won-stage requirement), the quote and deal are left unchanged and the error is shown in a notification. The Quotes relation manager on a deal does the same. |
| `SalesQuotaResource` | Attainment columns calculated with `CalculateQuotaAttainmentAction`. |
| `SalesSequenceResource` | **Enroll Contact** row action (`EnrollContactInSequenceAction`). The list page has a **Process Due Cadences** header action that runs `ProcessCadencesAction` for every due enrollment, for all users, during the request. |
| `SalesMeetingLinkResource` | Public URL column linking to route `odden.meetings.show`. The form's **Availability** section edits the link's [booking settings](../sales/meeting-links.md#working-hours): a searchable **Timezone** select (empty uses the app timezone), **Buffer Between Meetings** in minutes (0 to 240), and one tag input per weekday for working-hour windows such as `09:00-12:00`. Each window must be `HH:MM-HH:MM` with the start before the end. Days without windows are dropped, and leaving every day empty saves `null`, so the link uses `odden-sales.meetings.default_working_hours`. |
| `PipelineResource` | **Pipeline Stages** relation manager. |

## Add-on resources

Odden Marketing and Odden Service are separate, paid packages. When one is installed, it adds its own resources, pages and navigation group to the panel through [`Odden\Filament\Support\Modules`](modules.md); they are documented with the add-on.
