---
title: Core
description: What the getodden/crm-core package provides, the models it ships, and how the other Odden modules build on it.
---

`getodden/crm-core` is the foundation every other Odden package depends on. It owns the CRM records (contacts, companies, and custom objects), and the services that work across all of them: custom properties with change history, associations between any two records, the activity timeline, lifecycle stages, lists, and duplicate merging. It has no UI and registers no routes of its own. It only needs `laravel/framework` 12 or 13 and PHP 8.3+.

See [Installation](../installation.md) for installing Odden into your app. Core's service provider, `Odden\Core\CoreServiceProvider`, is auto-discovered. It:

- merges `config/odden-core.php` under the `odden-core` key,
- loads the package migrations (you don't need to publish them),
- registers `LifecycleStateMachine` and `EnrichmentManager` as singletons,
- defines the `odden-public` and `odden-api` rate limiters.

You can publish the config file or the migrations if you want to edit them:

```bash
php artisan vendor:publish --tag=odden-core-config
php artisan vendor:publish --tag=odden-core-migrations
```

## Models

All models live in `Odden\Core\Models`. Table names come from `odden-core.tables.*` (see [Users, routes, and configuration](integration.md#configuration-reference)).

| Model | Default table | What it stores |
| --- | --- | --- |
| `Contact` | `odden_contacts` | People: name, email, phone, job title, lifecycle stage, lead status, lead score, owner, team. Soft-deletes. |
| `Company` | `odden_companies` | Organizations: name, domain, industry, lifecycle stage, health score, account tier and intent fields. Soft-deletes. |
| `PropertyDefinition` | `odden_properties` | The registry of custom properties per entity type. |
| `PropertyHistory` | `odden_property_history` | One row per changed attribute or custom property. |
| `Association` | `odden_associations` | A directed link between any two records. |
| `AssociationType` | `odden_association_types` | Named association types with labels and cardinality. |
| `Activity` | `odden_activities` | Timeline entries: notes, calls, emails, meetings, tasks. |
| `CrmList`, `ListMembership` | `odden_lists`, `odden_list_memberships` | Static and active (criteria-based) lists. |
| `LifecycleStageTransition` | `odden_lifecycle_stage_transitions` | History of lifecycle stage changes with time spent in each stage. |
| `CustomObjectDefinition`, `CustomObjectRecord` | `odden_custom_object_definitions`, `odden_custom_object_records` | Your own record types. |

Behavior is shared through traits in `Odden\Core\Traits`, which you can also add to your own models:

| Trait | Adds | Used by |
| --- | --- | --- |
| `HasCustomProperties` | `getProperty()`, `setProperty()`, `setProperties()`, `whereProperty()` scope | `Contact`, `Company`, `CustomObjectRecord` |
| `AuditsProperties` | Writes `PropertyHistory` on every Eloquent update, `propertyHistory()` relation | `Contact`, `Company`, `CustomObjectRecord` |
| `HasAssociations` | `associateWith()`, `dissociateFrom()`, `isAssociatedWith()`, `getAssociated()`, `getAssociatedByLabel()` | `Contact`, `Company`, `CustomObjectRecord` |
| `HasActivities` | `activities()`, `timeline()`, `logActivity()`, `logNote()`, `logCall()`, `logTask()` | `Contact`, `Company`, `CustomObjectRecord` |
| `HasLifecycleStageTransitions` | `lifecycleTransitions()`, time-in-stage helpers | `Contact`, `Company` |
| `BelongsToTeam` | `forTeam(int $teamId)` scope | `Contact`, `Company`, `AssociationType`, `CustomObjectDefinition`, `CustomObjectRecord`, `LifecycleStageTransition` |

Core has no team model. `team_id` is a plain nullable integer column, and `forTeam()` is the only thing that reads it. Scoping queries by team is up to your app.

## Actions

Writes that have side effects go through action classes in `Odden\Core\Actions`. Resolve them from the container and call `execute()`:

```php
use Odden\Core\Actions\CreateContactAction;

$contact = app(CreateContactAction::class)->execute([
    'first_name' => 'Jane',
    'email' => 'jane@acme.com',
]);
```

Events are only dispatched by actions. Creating a model with `Contact::create()` or calling a trait method such as `logNote()` skips them. See [Events](events.md).

## How the other modules build on Core

The Sales package and the paid Marketing and Service add-ons don't extend Core's models. They:

- reference `Contact`, `Company`, `Activity`, and the enums directly, and add their own relations at boot with `resolveRelationUsing()`. For example, Sales adds `deals` to `Contact` and `Company`, and Service adds `tickets`.
- resolve the host app's user model through `Odden\Core\Support\UserModel` for owners, assignees, and authors.
- build their route groups with `RouteGroup::attributes()`, protect webhooks with the `RequireApiToken` middleware, and throttle public endpoints with the `odden-public` and `odden-api` limiters. See [Users, routes, and configuration](integration.md).
- add their own columns to `odden_contacts`. For example, Marketing adds `marketing_topics`, `sms_consent`, and `is_unengaged`. These are already fillable and cast on `Contact`, but the columns only exist once that package's migrations have run.

Some Core actions use what other modules add without depending on them. The [customer health score](contacts-and-companies.md#customer-health-scores) reads the `deals` and `tickets` relations when Sales and Service have registered them, and on a merge each module moves its own data by listening for [`ContactsMerged` and `CompaniesMerged`](duplicates-and-merging.md#what-each-module-moves).

## Pages in this section

- [Contacts and companies](contacts-and-companies.md): creating records, domain auto-association, enrichment, health scores.
- [Custom properties](custom-properties.md): property definitions, reading and writing values, change history, custom objects.
- [Associations](associations.md): linking records, association types, and cardinality.
- [Activities and the timeline](activities.md): logging activities and reading the timeline.
- [Lifecycle stages](lifecycle-stages.md): the stage state machine, transitions, and funnel velocity.
- [Lists](lists.md): static lists and active lists.
- [Duplicates and merging](duplicates-and-merging.md): finding duplicate contacts and companies, and merging them.
- [Events](events.md): the events Core dispatches.
- [Users, routes, and configuration](integration.md): the user model, route helpers, API tokens, rate limits, and every config key.
