---
title: Deals
description: Create deals, link them to contacts and companies, move them through stages, close them as won or lost, and manage line items.
---

An `Odden\Sales\Models\Deal` is an opportunity sitting in one stage of one pipeline. Deals have an amount, a status (`open`, `won`, or `lost`), an owner, and a full stage history. They are soft-deletable and use Core's activity, association, custom property, property audit, and team traits, so everything in [Core concepts](../core/index.md) applies to them.

## Attributes

| Attribute | Type | Notes |
| --- | --- | --- |
| `pipeline_id` | int | |
| `stage_id` | int | |
| `name` | string | |
| `amount` | decimal(15,2) | Defaults to `0.00`. Overwritten from line items when you use [products](#products). |
| `currency` | string(3) | Database default `USD`. |
| `status` | `DealStatus` | Database default `open`. |
| `expected_close_date` | date, nullable | Used by quotas and the health score. |
| `closed_at` | datetime, nullable | Set when the deal enters a closed stage. |
| `lost_reason` | string, nullable | Free text in the model; the Filament panel only offers the `LostReason` enum values. |
| `lost_notes` | string, nullable | |
| `properties` | array, nullable | Custom properties. |
| `owner_id` | int, nullable | The user who owns the deal (your configured user model). |
| `team_id` | int, nullable | |

`Odden\Sales\Enums\DealStatus` has `Open` (`open`), `Won` (`won`), and `Lost` (`lost`), with `label()`, `color()`, `isOpen()`, `isWon()`, and `isLost()` helpers.

`Odden\Sales\Enums\LostReason` has `Price`, `Competitor`, `FeatureGap`, `Timing`, `Unresponsive`, and `Other`, with `label()` and `color()`. The `lost_reason` column is a plain string, so pass `LostReason::Competitor->value` (or any other string).

## Creating a deal

```php
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;

$deal = Deal::create([
    'pipeline_id' => $pipeline->id,
    'stage_id' => $pipeline->defaultStage()->id,
    'name' => 'Acme renewal',
    'amount' => 12000,
    'status' => DealStatus::Open,
    'expected_close_date' => now()->addMonth(),
    'owner_id' => $user->id,
]);
```

`status` defaults to `open`, both in the database and on the model, so a deal created without it is usable immediately (`isRotten()`, `getHealthScore()`).

The `currency` column defaults to `USD` at the database level; set `currency` on the deal if you sell in another currency. When a deal has no currency, amounts are formatted with the `odden-sales.default_currency` config value.

Creating a deal does not write a stage history row or run [stage automations](pipelines-and-stages.md#stage-automations). Both happen only when the deal changes stage.

## Contacts and companies

Deals link to contacts and companies through Core associations, with the deal as the parent record:

```php
$deal->associateWith($contact);
$deal->associateWith($company);

$deal->contacts;   // Collection of Odden\Core\Models\Contact
$deal->companies;  // Collection of Odden\Core\Models\Company

$contact->deals;   // Added to Contact by the sales service provider
$company->deals;   // Added to Company by the sales service provider
```

## Moving between stages

Use `moveToStage()` on the deal, which calls `Odden\Sales\Actions\ChangeDealStageAction`:

```php
public function moveToStage(
    PipelineStage $stage,
    int|string|null $userId = null,
    ?string $lostReason = null,
    ?string $lostNotes = null,
): Deal
```

```php
$deal->moveToStage($proposal, $user->id);
```

Inside one database transaction, the action:

1. Runs the target stage's [stage automations](pipelines-and-stages.md#stage-automations). A failed requirement throws `StageRequirementException` and nothing is changed.
2. Closes the open stage history row: sets `exited_at` and `duration_in_stage_seconds`.
3. Sets `stage_id`, and `pipeline_id` from the target stage, so you can move a deal into another pipeline by passing a stage from that pipeline.
4. Sets `status` to `won` or `lost` and `closed_at` to now when the stage is closed won or closed lost. For any other stage it sets `status` to `open` and clears `closed_at`, so moving a closed deal back to an open stage reopens it.
5. Clears `lost_reason` and `lost_notes` when the target stage is not closed lost, unless you pass new values.
6. Writes a new `DealStageHistory` row. Its `user_id` is `$userId`, or the authenticated user if you pass `null`.
7. Dispatches `DealMovedStage`, plus `DealWon` or `DealLost` for closed stages. See [Events](#events).
8. When the deal is won or lost, sets every active sequence enrollment of the deal's associated contacts to `unenrolled`. See [Sequences](sequences.md).

The action does not check whether the deal is already in the target stage; moving a deal to its current stage records another history row and dispatches the events again.

## Won and lost

```php
use Odden\Sales\Enums\LostReason;

$deal->markLost(LostReason::Competitor->value, $user->id, 'Went with a cheaper vendor.');

$deal->markWon($user->id);
```

- `markWon(int|string|null $userId = null)` moves the deal to the pipeline's first stage with `is_closed_won`.
- `markLost(?string $reason = null, int|string|null $userId = null, ?string $notes = null)` moves the deal to the first stage with `is_closed_lost` and stores the reason and notes.

Both throw a `RuntimeException` (for example, `Pipeline [New Business] has no closed won stage defined.`) if the pipeline has no such stage.

Query scopes: `Deal::query()->open()`, `->won()`, and `->lost()`.

## Stage history

Every stage change writes an `Odden\Sales\Models\DealStageHistory` row with `from_stage_id`, `to_stage_id`, `user_id`, `entered_at`, `exited_at`, and `duration_in_stage_seconds`. The current stage's row has `exited_at = null`.

```php
$history = $deal->stageHistory()->with(['fromStage', 'toStage', 'user'])->get();

foreach ($history as $entry) {
    $entry->toStage->name;
    $entry->duration_in_stage_seconds; // null for the current stage
}
```

`stageHistory()` is ordered by `entered_at`, newest first. `$deal->daysInCurrentStage()` uses the newest row's `entered_at`, falling back to the deal's `created_at` when there is no history.

## Products

`Odden\Sales\Models\DealProduct` is a soft-deletable line item: `deal_id`, `name`, `sku`, `description`, `unit_price`, `quantity`, `discount_percent`, and `sort_order`. `$deal->products` returns them ordered by `sort_order`.

`total_price` is calculated on every save as `quantity × unit_price × (1 − discount_percent / 100)`, rounded to two decimals and never below zero. Any value you pass is overwritten.

`quantity` defaults to `1`, so a product created without it gets a `total_price` equal to its discounted `unit_price`.

Saving, deleting, or restoring a product recalculates the deal's `amount` as the sum of its products' `total_price` (via `SyncDealAmountAction`, which uses `updateQuietly`, so no model events or property audits fire for the change).

```php
use Odden\Sales\Models\DealProduct;

DealProduct::create([
    'deal_id' => $deal->id,
    'name' => 'Platform licence',
    'sku' => 'PLAT-001',
    'unit_price' => 1000,
    'quantity' => 10,
    'discount_percent' => 15,
]); // total_price 8500.00

DealProduct::create([
    'deal_id' => $deal->id,
    'name' => 'Onboarding',
    'unit_price' => 2500,
    'quantity' => 1,
]);

$deal->fresh()->amount; // "11000.00"
```

Once a deal has products, treat `amount` as derived: a manual amount is replaced the next time a product changes. Calling `$deal->syncAmountFromProducts()` on a deal with no products sets its amount to `0.00`.

## Events

All events are in `Odden\Sales\Events` and use `Dispatchable` and `SerializesModels`.

| Event | Properties | Dispatched when |
| --- | --- | --- |
| `DealMovedStage` | `Deal $deal`, `?PipelineStage $fromStage`, `PipelineStage $toStage`, `int\|string\|null $userId` | Every stage change. |
| `DealWon` | `Deal $deal`, `int\|string\|null $userId` | The deal enters a closed won stage. |
| `DealLost` | `Deal $deal`, `?string $reason`, `int\|string\|null $userId` | The deal enters a closed lost stage. |

The events are dispatched inside the stage-change transaction. `$userId` is the value you passed, not the authenticated-user fallback used for the history row, so it is `null` when the change came from [quote acceptance](quotes.md#what-acceptance-does). If you queue listeners, consider `ShouldQueueAfterCommit` so they don't run before the transaction commits.

```php
use Odden\Sales\Events\DealWon;
use Illuminate\Support\Facades\Event;

Event::listen(function (DealWon $event): void {
    // Start onboarding for $event->deal
});
```
