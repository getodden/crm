---
title: Custom properties
description: Define custom properties, read and write their values on contacts, companies, and custom object records, query the change history, and create custom objects.
---

Every contact, company, and custom object record has a `properties` JSON column for fields that aren't built-in columns. `PropertyDefinition` records describe those fields, and every change to a record's columns or properties is written to `PropertyHistory`.

## Property definitions

`Odden\Core\Models\PropertyDefinition` (table `odden_properties`) is a registry of the properties you expect on an entity type. The Filament package uses it to build forms and tables.

| Column | Notes |
| --- | --- |
| `entity_type` | Free-form string identifying the record type. Unique together with `name`. |
| `name` | The key inside `properties`. |
| `label` | Display label. |
| `type` | `PropertyType` enum, default `text`. |
| `group_name` | Default `general`. |
| `options` | JSON, cast to array. For example the choices of a select. |
| `description` | Nullable text. |
| `is_required`, `is_searchable` | Booleans, default `false`. |
| `sort_order` | Unsigned integer, default `0`. |

```php
use Odden\Core\Enums\PropertyType;
use Odden\Core\Models\Contact;
use Odden\Core\Models\PropertyDefinition;

PropertyDefinition::create([
    'entity_type' => (new Contact)->getMorphClass(),
    'name' => 'plan',
    'label' => 'Plan',
    'type' => PropertyType::Select,
    'group_name' => 'billing',
    'options' => ['choices' => ['starter', 'growth', 'enterprise']],
]);

$definitions = PropertyDefinition::forEntity(Contact::class)->get();
```

The `forEntity(string $entityType)` scope filters by `entity_type` and orders by `sort_order`. Without a morph map, a model's morph class is its class name, so `(new Contact)->getMorphClass()` and `Contact::class` are the same value.

Core doesn't validate property values against definitions. `setProperty()` and `setProperties()` accept any key and value, and `is_required`, `type`, and `options` are not enforced. Validate input yourself, for example in a form request, before writing it.

`Odden\Core\Enums\PropertyType` cases. Each has a `label()`.

| Case | Value | `label()` |
| --- | --- | --- |
| `Text` | `text` | Single-line Text |
| `Number` | `number` | Number |
| `Boolean` | `boolean` | Boolean (True/False) |
| `Select` | `select` | Dropdown Select |
| `MultiSelect` | `multi_select` | Multiple Checkboxes |
| `Date` | `date` | Date Picker |
| `DateTime` | `datetime` | Date and Time |
| `Json` | `json` | JSON Object |

## Reading and writing values

The `HasCustomProperties` trait casts `properties` to an array and adds these methods:

| Method | Behavior |
| --- | --- |
| `getProperty(string $name, mixed $default = null): mixed` | Reads with `data_get()`, so dot notation reaches into nested arrays. |
| `setProperty(string $name, mixed $value): static` | Sets one top-level key. Doesn't save. |
| `setProperties(array $properties): static` | Merges keys into the existing array with `array_merge()`. Doesn't save. |
| `whereProperty(string $name, mixed $value)` scope | `where("properties->{$name}", $value)`. |

```php
$contact->setProperty('plan', 'growth')->save();
$contact->setProperties(['seats' => 25, 'billing' => ['currency' => 'EUR']])->save();

$contact->getProperty('plan');             // "growth"
$contact->getProperty('billing.currency'); // "EUR"
$contact->getProperty('missing', 'n/a');   // "n/a"

Contact::whereProperty('plan', 'growth')->get();
```

You can also pass a `properties` array when creating a record. It replaces the whole array, it doesn't merge.

## Change history

The `AuditsProperties` trait listens to the Eloquent `updating` event. For each changed attribute it writes an `Odden\Core\Models\PropertyHistory` row (table `odden_property_history`):

| Column | Value |
| --- | --- |
| `auditable_type`, `auditable_id` | The record. |
| `property_name` | The column name. For the `properties` column, one row per changed key, named after the key. |
| `old_value`, `new_value` | Scalars cast to string, enums stored as their backing value, other values JSON-encoded. |
| `user_id` | `auth()->id()` at the time of the change, or `null`. |
| `source` | The request's `X-Odden-Source` header, defaulting to `web`. The default applies in queued jobs and console commands too. |
| `created_at` | `now()`. |

`updated_at`, `deleted_at`, and `remember_token` are never recorded. Creating a record writes no history.

```php
$contact = Contact::create([
    'email' => 'jane@acme.com',
    'job_title' => 'Engineer',
    'properties' => ['plan' => 'starter'],
]);

$contact->update(['job_title' => 'VP Engineering']);
$contact->setProperty('plan', 'growth')->save();

foreach ($contact->propertyHistory as $entry) {
    echo "{$entry->property_name}: {$entry->old_value} → {$entry->new_value}";
}
```

`propertyHistory()` is a `MorphMany` ordered newest first. Each entry has `auditable()` and `user()` relations.

History is only written for changes made through Eloquent model events. These changes write no history:

- `saveQuietly()`, `updateQuietly()`, and query-builder updates such as `Contact::where(...)->update([...])`.
- the marketing verification token that `getPreferenceCenterUrl()` generates, which is saved quietly because it is a secret.

Lifecycle stage changes from `TransitionLifecycleStageAction`, `Contact::markContacted()`, and company [enrichment](contacts-and-companies.md#enrichment) do write history, in addition to the [lifecycle transition](lifecycle-stages.md) row where that applies.

To tag changes that come from an import or an integration, have the client send an `X-Odden-Source` header, such as `X-Odden-Source: import`, on the HTTP request that makes them. Changes made outside an HTTP request are always recorded as `web`.

## Custom objects

Custom objects let you add your own record types, such as licenses or projects, without writing migrations. A `CustomObjectDefinition` describes the type, and each `CustomObjectRecord` stores its data in `properties`. Records use the same traits as contacts and companies: custom properties, change history, associations, activities, soft deletes, and the `forTeam()` scope. They don't have lifecycle stages.

```php
use Odden\Core\Actions\CreateCustomObjectDefinitionAction;
use Odden\Core\Actions\CreateCustomObjectRecordAction;

$definition = app(CreateCustomObjectDefinitionAction::class)->execute([
    'name' => 'Software License',
    'singular_label' => 'License',
    'primary_display_property' => 'license_key',
    'secondary_display_properties' => ['seats', 'expires_on'],
    'icon' => 'heroicon-o-key',
]);

$definition->name;         // "software_license"
$definition->plural_label; // "Licenses"

$license = app(CreateCustomObjectRecordAction::class)->execute('software_license', [
    'properties' => ['license_key' => 'ACME-2026-001', 'seats' => 50],
]);

$license->name;                  // "ACME-2026-001"
$license->getProperty('seats');  // 50

$company->associateWith($license, 'licensed');
$license->logNote('Renewal discussed.');
```

`CreateCustomObjectDefinitionAction::execute(array $attributes): CustomObjectDefinition`:

- converts `name` to a snake-case slug. Names are unique.
- derives `plural_label` from `singular_label` when you don't pass one.
- dispatches `CustomObjectDefinitionCreated`.

`primary_display_property` defaults to `name`.

`CreateCustomObjectRecordAction::execute(CustomObjectDefinition|string $definition, array $attributes): CustomObjectRecord`:

- accepts a definition model or its `name`. An unknown name throws `InvalidArgumentException`.
- sets `definition_id`.
- when `name` is empty, uses the value of the definition's `primary_display_property` from `properties`, or falls back to `"{singular_label} #<uniqid>"`.
- dispatches `CustomObjectRecordCreated`.

`$definition->records()` returns the records of a type, and `$record->definition` returns its definition. Records also have an `owner()` relation to your user model. Deleting a definition deletes its records (cascade at the database level).

You can attach `PropertyDefinition` rows to a custom object type by choosing an `entity_type` value for it, but Core doesn't link the two.
