---
title: Associations
description: Link any two CRM records, define association types with labels and cardinality, and query associated records.
---

An association is a directed link from a parent record to a child record, stored in `odden_associations`. Any two Eloquent models can be linked: contacts, companies, custom object records, or other modules' models such as deals. Contacts, companies, and custom object records get helper methods from the `HasAssociations` trait.

## Linking records

```php
$association = $contact->associateWith($company, 'employee');

$contact->isAssociatedWith($company);              // true
$company->isAssociatedWith($contact, 'employee');  // true

$contact->getAssociated(Company::class);             // Collection of companies
$company->getAssociated(Contact::class, 'employee'); // only "employee" links

$contact->dissociateFrom($company); // number of rows deleted
```

`HasAssociations` methods:

| Method | Behavior |
| --- | --- |
| `associateWith(Model $record, string\|AssociationType $type = 'default', ?string $label = null): Association` | Calls `AssociateRecordsAction` with `$this` as the parent. |
| `dissociateFrom(Model $record, ?string $type = null): int` | Deletes links in either direction, optionally only of one type. |
| `isAssociatedWith(Model $record, ?string $type = null): bool` | Checks both directions. |
| `getAssociated(string $modelClass, ?string $type = null): Collection` | Records of `$modelClass` linked in either direction. |
| `getAssociatedByLabel(string $modelClass, string $label): Collection` | Records linked with a matching association label, or whose association type has that `label` or `reverse_label`. |
| `associationsAsParent()`, `associationsAsChild()` | `MorphMany` relations to the raw `Association` rows. |

The `type` column holds a type name, with `default` as the default. A link is unique per parent, child, and type, so `associateWith()` with the same arguments returns the existing row. The same two records can be linked under different types.

Direction matters for some queries. `Contact::companies()` and `Company::contacts()` only see links where the contact is the parent and the company the child. That's the direction `associateWith()` uses when you call it on the contact, and the one domain auto-association uses. The trait methods above check both directions.

You can also call the action directly:

```php
use Odden\Core\Actions\AssociateRecordsAction;

app(AssociateRecordsAction::class)->execute($company, $contact, 'billing_contact');
```

`AssociateRecordsAction::execute(Model $parent, Model $child, string|AssociationType $type = 'default', ?string $label = null): Association`:

1. Resolves the association type. If you pass a string, it looks for an `AssociationType` with that `name`. If none exists, the string is still stored as `type` and no rules apply.
2. Enforces the type's cardinality, throwing `Odden\Core\Exceptions\CardinalityViolationException`.
3. Creates the link, or updates the existing one. It sets `association_type_id` and the `label`, which defaults to the type's `label`.
4. Dispatches `RecordsAssociated`, only when a new row was created.

Merging records moves their associations to the surviving record. See [Duplicates and merging](duplicates-and-merging.md).

## Association types

`Odden\Core\Models\AssociationType` (table `odden_association_types`) gives a type name a label, an optional reverse label, and a cardinality rule.

| Column | Notes |
| --- | --- |
| `name` | Unique. This is what `associations.type` stores. |
| `label` | Label shown from the parent's side. |
| `reverse_label` | Label shown from the child's side. Nullable. |
| `cardinality` | `AssociationCardinality`, default `many_to_many`. |
| `from_record_type`, `to_record_type` | Morph classes. `getLabelFor()` uses them, and `AssociateRecordsAction` enforces them (see below). |
| `is_system` | Boolean, default `false`. A system type can't be deleted, and its `name`, `from_record_type` and `to_record_type` can't change (labels and cardinality can); both throw `SystemAssociationTypeException`. |
| `team_id` | Nullable. |

```php
use Odden\Core\Actions\AssociateRecordsAction;
use Odden\Core\Actions\CreateAssociationTypeAction;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

$type = app(CreateAssociationTypeAction::class)->execute([
    'name' => 'billing_contact',
    'label' => 'Billing contact',
    'reverse_label' => 'Billing contact for',
    'cardinality' => 'one_to_one',
    'from_record_type' => (new Company)->getMorphClass(),
    'to_record_type' => (new Contact)->getMorphClass(),
]);

$association = app(AssociateRecordsAction::class)->execute($company, $jane, $type);

$association->label;      // "Billing contact"
$type->getLabelFor($jane); // "Billing contact for"

$company->getAssociatedByLabel(Contact::class, 'Billing contact');
$jane->getAssociatedByLabel(Company::class, 'Billing contact for');

$company->associateWith($sam, 'billing_contact'); // throws CardinalityViolationException
```

`CreateAssociationTypeAction::execute(array $attributes): AssociationType` converts a string `cardinality` to the enum and creates the row. `AssociationType::getLabelFor(Model $record)` returns `reverse_label` when the record's morph class equals `to_record_type`, and `label` otherwise.

When `from_record_type` and `to_record_type` are both set, the two records must be of those types, in either order. When only one is set, one of the two records must be of that type. Otherwise `AssociateRecordsAction` (and so `associateWith()`) throws `Odden\Core\Exceptions\InvalidAssociationException` and nothing is saved. With neither set, any pair of models can be linked.

### Cardinality

`Odden\Core\Enums\AssociationCardinality`:

| Case | Value | Rule checked by `AssociateRecordsAction` |
| --- | --- | --- |
| `ManyToMany` | `many_to_many` | None. |
| `OneToMany` | `one_to_many` | A child can have at most one parent of this type. |
| `OneToOne` | `one_to_one` | A parent can have at most one child, and a child at most one parent, of this type. |

Rules apply only to links created with a type that exists as an `AssociationType` row. Re-linking the same parent and child never violates them.

## Free-form labels

Without a type, you can still label a link:

```php
$association = $jane->associateWith($sam, 'referral', 'Referred by');

$association->label;               // "Referred by"
$association->association_type_id; // null
```

## The Association model

`Odden\Core\Models\Association` has `parent()` and `child()` morph relations, and `associationType()`, which belongs to `AssociationType`. Query it directly when you need the raw links:

```php
use Odden\Core\Models\Association;

$links = Association::query()
    ->where('parent_type', $contact->getMorphClass())
    ->where('parent_id', $contact->getKey())
    ->with('child')
    ->get();
```
