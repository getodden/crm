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
| **AI Briefing** | Shows a summary from `Odden\Core\Actions\SummarizeTimelineAction` in a modal. |
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

## Service

Registered when `getodden/crm-service` is installed. See the [service module docs](../service/index.md).

| Resource | Model | Navigation | Pages |
| --- | --- | --- | --- |
| `TicketResource` | `Odden\Service\Models\Ticket` | Service › Tickets | list, board, create, edit |
| `SlaPolicyResource` | `Odden\Service\Models\SlaPolicy` | Service › SLA Policies | list, create, edit |
| `KnowledgeArticleResource` | `Odden\Service\Models\KnowledgeArticle` | Service › Knowledge Base | list, create, edit |
| `CannedResponseResource` | `Odden\Service\Models\CannedResponse` | Service › Canned Responses | list, create, edit |
| `TicketRoutingRuleResource` | `Odden\Service\Models\TicketRoutingRule` | Service › Ticket Routing Rules | list, create, edit |

### Tickets

- **List page:** a **Tickets Board** button. Row actions are **Resolve** (`ResolveTicketAction`, with a resolution note), **Merge** (`MergeTicketsAction`), **Portal** (opens `Ticket::getPortalUrl()`) and **Auto-Route** (`RouteTicketAction`).
- **Board page** (`/tickets/board`): a kanban by ticket status. Dragging a card updates the ticket's `status`.
- **Edit page:** the **Conversation Thread & Notes** relation manager, with an **Add Reply / Note** action that calls `ReplyTicketAction`.

Side effects to be aware of:

- **Add Reply / Note** with a public reply sends the ticket's contact a `TicketRepliedNotification` email and logs a note on the contact's timeline.
- **Resolve** sends the contact a `TicketResolvedCsatNotification` email.

Neither notification implements `ShouldQueue`, so the mail is sent during the request.

## Marketing

Registered when `getodden/crm-marketing` is installed. See the [marketing module docs](../marketing/index.md).

| Resource | Model | Navigation | Pages |
| --- | --- | --- | --- |
| `CampaignResource` | `Odden\Marketing\Models\Campaign` | Marketing › Campaigns | list, create, edit |
| `MarketingTemplateResource` | `Odden\Marketing\Models\MarketingTemplate` | Marketing › Email Templates | list, create, edit |
| `MarketingFormResource` | `Odden\Marketing\Models\MarketingForm` | Marketing › Lead Capture Forms | list, create, edit |
| `MarketingWorkflowResource` | `Odden\Marketing\Models\MarketingWorkflow` | Marketing › Marketing Workflows | list, create, edit |
| `LandingPageResource` | `Odden\Marketing\Models\LandingPage` | Marketing › Landing Pages | list, create, edit |
| `LeadScoringRuleResource` | `Odden\Marketing\Models\LeadScoringRule` | Marketing › Lead Scoring Rules | list, create, edit |
| `NpsSurveyResource` | `Odden\Marketing\Models\NpsSurvey` | Marketing › Nps Surveys | list, create, edit |
| `MarketingSubscriptionResource` | `Odden\Marketing\Models\MarketingSubscription` | Marketing › Suppression List | list, create (no edit page) |
| `MarketingAssetResource` | `Odden\Marketing\Models\MarketingAsset` | Marketing › Marketing Assets | list, create, edit |
| `MarketingEventResource` | `Odden\Marketing\Models\MarketingEvent` | Marketing › Marketing Events | list, create, edit |
| `AdAudienceSyncResource` | `Odden\Marketing\Models\AdAudienceSync` | Marketing › Ad Audience Sync Bridges | list, create, edit |

### Campaigns

Row actions:

| Action | What it does |
| --- | --- |
| **Preview** | Renders the campaign with sample data in a modal. |
| **Spam Audit** | Runs `AuditCampaignDeliverabilityAction` and shows the result. |
| **Send Now** | Shown for draft and scheduled campaigns. Runs `DispatchCampaignAction` immediately, during the request, against all targeted recipients. |
| **Pick Winner & Deploy** | Shown for A/B campaigns that are sending and have no winner yet. Runs `EvaluateAbTestWinnerAction`, which also sends the winning variant to the remaining audience. |
| **AI Copy Assistant** | Generates subject lines with `GenerateAiSubjectLinesAction` and saves the one you pick to the campaign. |
| **Send Test** | Sends a proof to the addresses you enter with `SendCampaignProofAction`, optionally using a contact's data for merge tags. The proof is queued like other marketing mail (see [Sending mail](../marketing/index.md#sending-mail)), so a queue worker must be running for it to arrive. |
| **Duplicate** | Creates a draft copy named "Copy of …" with the delivery counters reset. |

The edit page also has **Send Test** and **Duplicate** header actions.

### Other marketing resources

| Resource | Extra actions |
| --- | --- |
| `MarketingTemplateResource` | Layout presets and reusable snippets in the form, **Generate AI Variants**, **Live Preview**, **Send Test** (a one-off preview sent with `Mail::html()` during the request, not queued like campaign proofs), **Evaluate A/B**, **Revisions**, **Export HTML**, **Export MJML**, **Download ZIP**, and **Replicate**. The presets and the block editor come from `getodden/mail`, which `getodden/crm-marketing` requires. |
| `MarketingFormResource` | **Embed Code** modal and a public URL column. |
| `LandingPageResource` | **Embed Snippet** modal and a public URL column. |
| `MarketingWorkflowResource` | **Visual Journey** modal and the **Workflow Execution Steps & Branching** relation manager. |
| `MarketingSubscriptionResource` | **Restore / Resubscribe** for non-subscribed rows and **Suppress** for subscribed rows. |
| `MarketingAssetResource` | **Copy URL** for the asset's download link. |
| `AdAudienceSyncResource` | **Sync Now**, which runs `SyncAdAudienceAction`. |
