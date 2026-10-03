---
title: Duplicates and merging
description: Find duplicate contacts and companies, and merge a duplicate into a surviving record.
---

Core has two finder actions that group records with identical values, and two merge actions that fold a secondary record into a primary one and soft-delete the secondary.

## Finding duplicate contacts

`FindDuplicateContactsAction::execute(): array` scans all contacts (soft-deleted ones excluded) and returns groups:

```php
use Odden\Core\Actions\FindDuplicateContactsAction;

$groups = app(FindDuplicateContactsAction::class)->execute();

// [
//     [
//         'match_field' => 'email',
//         'match_value' => 'jane@acme.com',
//         'contacts' => Collection of Contact, ordered by id,
//     ],
// ]
```

It matches on:

1. `email`: the same address, ignoring case and surrounding whitespace. `match_value` is the lowercased address.
2. `phone`: the same digits, ignoring formatting, so `(555) 010-1234` and `555.010.1234` match. `match_value` is the digits only.
3. `name`: the same first and last name, ignoring case and surrounding whitespace. Contacts missing either name are never matched on name. `match_value` is the lowercased `first last`.

Fields are checked in that order, and a group of exactly the same contacts is reported once, under the first field that matched. The action takes no arguments, so you can't scope it to one contact or one team.

## Finding duplicate companies

`FindDuplicateCompaniesAction::execute(): array` returns groups with `match_field`, `match_value`, and `companies`. It matches on:

1. `domain`: exactly the same non-empty value.
2. `name`: exactly the same value. A name group is skipped if the same set of companies was already matched by domain.

## Merging contacts

```php
use Odden\Core\Actions\MergeContactsAction;

$survivor = app(MergeContactsAction::class)->execute($primary, $secondary);
```

`execute(Contact $primary, Contact $secondary, array $fieldOverrides = []): Contact` runs in a database transaction:

1. Copies `first_name`, `last_name`, `phone`, `lifecycle_stage`, `lead_status`, `owner_id`, and `team_id` from the secondary where the primary's value is empty.
2. Applies `$fieldOverrides` to the primary.
3. Keeps the higher `lead_score`.
4. Merges `properties`. The primary's values win when both have the same key.
5. Keeps the earlier date of each lifecycle stage timestamp (`became_lead_at`, `became_customer_at`, and so on).
6. Saves the primary. This is a normal save, so [property history](custom-properties.md#change-history) is written.
7. Moves everything Core stores against the secondary to the primary:
    - activities,
    - associations, in both directions. Links that would duplicate one the primary already has are deleted, and so is any link between the two records being merged. Deals from Sales are linked through associations, so they move here.
    - static and active [list](lists.md) memberships. Memberships of lists the primary is already on are deleted.
    - [property history](custom-properties.md#change-history),
    - [lifecycle stage transitions](lifecycle-stages.md).
8. Dispatches [`ContactsMerged`](#moving-your-own-data-on-merge). Each installed module moves its own data in a listener; see [What each module moves](#what-each-module-moves).
9. Logs a "Contact Merged" note on the primary.
10. Soft-deletes the secondary.

If any step throws, including a `ContactsMerged` listener, the whole merge is rolled back.

It returns the refreshed primary.

```php
$survivor = app(MergeContactsAction::class)->execute(
    $primary,
    $secondary,
    fieldOverrides: ['job_title' => 'CTO'],
);
```

Data that your own app or package stores against the contact by its ID is only moved if you [listen for `ContactsMerged`](#moving-your-own-data-on-merge).

To merge every group the finder returns, keeping the oldest record of each group:

```php
use Odden\Core\Actions\FindDuplicateContactsAction;
use Odden\Core\Actions\MergeContactsAction;

foreach (app(FindDuplicateContactsAction::class)->execute() as $group) {
    $survivor = $group['contacts']->shift();

    foreach ($group['contacts'] as $duplicate) {
        $survivor = app(MergeContactsAction::class)->execute($survivor, $duplicate);
    }
}
```

## Merging companies

`MergeCompaniesAction::execute(Company $primary, Company $secondary, array $fieldOverrides = []): Company` works the same way, with these differences:

- It fills empty `domain`, `phone`, `industry`, `account_tier`, `owner_id`, and `team_id` from the secondary.
- It keeps the higher `intent_score`, sets `intent_surge` if either record has it, and sets `health_score` to the average of the two.
- Deals and contacts are linked through associations, so they move with the associations.
- It dispatches [`CompaniesMerged`](#moving-your-own-data-on-merge) instead of `ContactsMerged`.
- It logs a "Company Merged" note on the primary.
- It then runs [`CalculateCustomerHealthScoreAction`](contacts-and-companies.md#customer-health-scores) on the primary, which overwrites the averaged health score. This runs after `CompaniesMerged` listeners, so the score includes any records they moved. If the company moves into `AtRisk`, this also creates a churn-risk task.

```php
use Odden\Core\Actions\MergeCompaniesAction;

$company = app(MergeCompaniesAction::class)->execute($acme, $acmeInc);
```

## What each module moves

Core moves only its own data. Sales, Service, and Marketing each register a synchronous listener for `ContactsMerged` and `CompaniesMerged`, so when a module is installed its data moves inside the same transaction. Table names come from each module's `tables` config.

| Module | On a contact merge | On a company merge |
| :--- | :--- | :--- |
| Sales | Sequence enrollments and meeting bookings. Deals move with Core's associations. | Nothing: deals move with Core's associations. |
| Service | Tickets (soft-deleted ones too) and ticket messages. | Tickets. |
| Marketing | Form submissions, campaign recipients, global subscriptions, topic preferences, lead score and decay logs, workflow enrollments and logs, visitor sessions and page views, SMS messages, NPS responses, asset downloads, event registrations, and custom behavioural events. | Custom behavioural events. |

Where both records have a row that can only exist once, the modules keep one:

- **Sales sequence enrollments.** One enrollment per sequence is kept. A completed or unenrolled enrollment wins over an active one, so outreach the contact already finished or was taken out of isn't restarted. Otherwise the further-along enrollment is kept. See [Sequences](../sales/sequences.md#merging-contacts).
- **Marketing campaign recipients** (unique per campaign and contact). The recipient that unsubscribed is kept, otherwise the most engaged one (clicked, then opened, then sent). It takes the earliest sent, opened, and clicked times of the two. If the other row was sent, it's detached from the contact (`contact_id` set to `null`) so the unsubscribe and tracking links in that email keep working; if it wasn't sent, its ESP events are pointed at the kept row and it's deleted.
- **Marketing event registrations** (unique per event and contact). The attended registration is kept, then one that isn't cancelled, then the earliest. Missing UTM fields are filled from the other, and the event's `registrations_count` and `attendees_count` are corrected.
- **Marketing workflow enrollments.** If both contacts are active in the same workflow, the earlier enrollment stays active and the other is set to `exited`, so steps aren't sent twice.
- **Marketing subscriptions and topic preferences.** These are keyed by email, so the secondary's rows stay with its address. If the secondary unsubscribed, globally or from a topic, the primary's address is unsubscribed too. A merge never resubscribes anyone. See [Subscriptions and compliance](../marketing/subscriptions-and-compliance.md#merging-contacts).

## Moving your own data on merge

Core only moves data it owns, and the Odden modules move theirs. If your package or app stores rows against a contact or company by ID, listen for `Odden\Core\Events\ContactsMerged` or `Odden\Core\Events\CompaniesMerged` and move them from `$event->secondary` to `$event->primary`:

```php
use Odden\Core\Events\ContactsMerged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

Event::listen(function (ContactsMerged $event): void {
    DB::table('app_contract_signatures')
        ->where('contact_id', $event->secondary->id)
        ->update(['contact_id' => $event->primary->id]);
});
```

Both events are dispatched synchronously inside the merge's transaction, after Core has moved its own data and before the secondary is soft-deleted:

- Don't make these listeners queued. A queued listener runs after the transaction commits, outside it, so a failure can't roll the merge back.
- An exception in a listener rolls back the whole merge, including Core's changes.
- If your table has a unique index that includes the contact or company ID, delete or combine the secondary's conflicting rows before updating the rest.
- Rows linked through associations, such as deals, have already moved. You don't need to handle them.
