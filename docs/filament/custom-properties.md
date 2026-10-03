---
title: Custom properties in forms
description: How property definitions become form fields on the contact, company and deal resources, and how to reuse the field builder in your own resources.
---

Odden stores [custom properties](../core/custom-properties.md) in each record's `properties` JSON column and describes them with `Odden\Core\Models\PropertyDefinition` records. The Filament plugin reads those definitions and adds matching fields to forms, so a new property shows up without code changes.

## Where the fields appear

Fields are added to these forms:

| Resource | Entity type |
| --- | --- |
| `ContactResource` | `contact` |
| `CompanyResource` | `company` |
| `DealResource` | `deal` |

When at least one definition exists for the entity type, the form ends with a collapsible **Custom Properties** section. With no definitions, the section isn't shown.

The plugin adds properties to forms. For definitions marked `is_searchable`, it also adds a column to the resource's table, searched with a `LIKE` on that key in the `properties` column and hideable from the column picker. It doesn't add filters or infolist entries.

## Defining properties in the panel

Manage definitions under **Settings › Custom Properties** (`PropertyDefinitionResource`, `/admin/property-definitions`). The form fields map to `PropertyDefinition` attributes:

| Field | Attribute | Notes |
| --- | --- | --- |
| Entity Type | `entity_type` | `contact` and `company`, plus `deal` when `getodden/crm-sales` is installed. |
| Internal Key | `name` | Must match `^[a-z0-9_]+$`. This becomes the key in the `properties` column. |
| Display Label | `label` | The field label. |
| Data Type | `type` | An `Odden\Core\Enums\PropertyType` case. |
| Property Group | `group_name` | Defaults to `general`. |
| Help Text / Description | `description` | Shown as helper text under the field. |
| Dropdown Options | `options` | Value to label pairs. Only shown for Select and Multi-Select. |
| Required field | `is_required` | Makes the form field required. |
| Searchable in lists | `is_searchable` | Adds a searchable table column for the property. |

With Sales installed you can add properties to deals from the panel by choosing the `deal` entity type. You can also create the definitions in code, for example in a seeder or migration:

```php
use Odden\Core\Enums\PropertyType;
use Odden\Core\Models\PropertyDefinition;

PropertyDefinition::create([
    'entity_type' => 'deal',
    'name' => 'procurement_contact',
    'label' => 'Procurement Contact',
    'type' => PropertyType::Text,
    'group_name' => 'general',
]);
```

## Field types

`Odden\Filament\Support\CustomPropertyFieldBuilder` maps each `PropertyType` to a Filament field named `properties.{name}`:

| `PropertyType` | Filament field |
| --- | --- |
| `Text` | `TextInput` |
| `Number` | `TextInput::numeric()` |
| `Boolean` | `Toggle` |
| `Select` | `Select` with the definition's `options` |
| `MultiSelect` | `Select::multiple()` with the definition's `options` |
| `Date` | `DatePicker` |
| `DateTime` | `DateTimePicker` |
| `Json` | `KeyValue` |

Fields are ordered by the definition's `sort_order`. Each field uses the definition's `label`, is marked required when `is_required` is true, and shows `description` as helper text.

When the form is saved, the values are written to the record's `properties` column through Filament's dot-notation state. For example, a contact created with an `annual_budget` property of `50000` gets `$contact->getProperty('annual_budget') === 50000`.

The builder checks that the properties table exists before it queries definitions (the table name comes from the `odden-core.tables.properties` config key and defaults to `odden_properties`). If it doesn't exist, no section is added, so forms still render before you've run migrations.

## Using the builder in your own resources

`CustomPropertyFieldBuilder::makeSection(string $entityType): array` returns either an empty array or an array holding a single **Custom Properties** section. Spread it into any form schema whose model uses the `Odden\Core\Traits\HasCustomProperties` trait:

```php
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Odden\Filament\Support\CustomPropertyFieldBuilder;

public static function form(Schema $schema): Schema
{
    return $schema
        ->components([
            TextInput::make('email')->email()->required(),
            ...CustomPropertyFieldBuilder::makeSection('contact'),
        ]);
}
```

The entity type you pass must match the `entity_type` of the definitions you want.

## Property history

The contact, company and deal resources also show a read-only **Property History / Audit Trail** relation manager. It lists the record's `propertyHistory` rows: property, old value, new value, changed by (or "System"), source, and when the change happened.
