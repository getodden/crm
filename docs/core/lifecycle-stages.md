---
title: Lifecycle stages
description: Move contacts and companies through lifecycle stages with the state machine, record transitions, add guards, and measure funnel velocity.
---

Contacts and companies have a `lifecycle_stage` column cast to `Odden\Core\Enums\LifecycleStage`. Change it with `TransitionLifecycleStageAction`, which validates the change against `LifecycleStateMachine`, stamps a `became_<stage>_at` column, records a `LifecycleStageTransition`, and dispatches `LifecycleStageChanged`.

## Stages

| Case | Value | `label()` |
| --- | --- | --- |
| `Subscriber` | `subscriber` | Subscriber |
| `Lead` | `lead` | Lead |
| `MarketingQualifiedLead` | `marketing_qualified_lead` | Marketing Qualified Lead (MQL) |
| `SalesQualifiedLead` | `sales_qualified_lead` | Sales Qualified Lead (SQL) |
| `Opportunity` | `opportunity` | Opportunity |
| `Customer` | `customer` | Customer |
| `Evangelist` | `evangelist` | Evangelist |
| `Other` | `other` | Other |

New contacts and companies default to `lead` at the database level.

## Transitioning a record

```php
use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Enums\LifecycleStage;

$transition = app(TransitionLifecycleStageAction::class)->execute(
    $contact,
    LifecycleStage::MarketingQualifiedLead,
    source: 'lead_scoring',
    userId: auth()->id(),
);

$transition->from_stage;                       // LifecycleStage::Lead
$contact->lifecycle_stage;                     // LifecycleStage::MarketingQualifiedLead
$contact->became_marketing_qualified_lead_at;  // now
```

`execute(Model $record, LifecycleStage $toStage, string $source = 'manual', ?int $userId = null, bool $force = false): LifecycleStageTransition`

1. Validates the transition (see below), unless `$force` is `true`.
2. Sets `lifecycle_stage`, and sets `became_<stage>_at` to `now()` if it is still `null`, so it keeps the first time the record reached that stage.
3. Saves normally, so model events fire and the change to `lifecycle_stage` (and `became_<stage>_at`) is written to the [property history](custom-properties.md#change-history).
4. Creates a `LifecycleStageTransition` with `duration_seconds` set to the time since the previous transition, or since the record was created.
5. Dispatches `LifecycleStageChanged`.

Setting `lifecycle_stage` directly with `update()` bypasses all of this: no validation, no transition row, no event.

`$source` is a free-form string. It is stored on the transition and used by the customer-regression rule below.

## Validation rules

`LifecycleStateMachine::validateTransition()` throws `Odden\Core\Exceptions\InvalidLifecycleStageTransitionException` when a transition isn't allowed. It applies these rules in order:

1. A record with no current stage, or a transition to its current stage, is always allowed.
2. In strict mode, the transition must be in the allowed graph (below).
3. A `Customer` cannot move back to `Subscriber`, `Lead`, `MarketingQualifiedLead`, `SalesQualifiedLead`, or `Opportunity` unless `$source` is one of `churn`, `recycle`, `downgrade`, `disqualified`, or `refund` (case-insensitive). This rule applies even when strict mode is off.
4. Every registered guard must not return `false`.

Passing `force: true` skips all of them.

```php
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;

try {
    app(TransitionLifecycleStageAction::class)->execute($customer, LifecycleStage::Lead);
} catch (InvalidLifecycleStageTransitionException $e) {
    app(TransitionLifecycleStageAction::class)->execute($customer, LifecycleStage::Lead, source: 'churn');
}
```

### Strict mode

Strict mode is off by default, so any stage can move to any other stage, subject to the customer rule. Turn it on in config:

```php
// config/odden-core.php
'lifecycle' => [
    'strict_transitions' => true,
],
```

Or at runtime: `app(LifecycleStateMachine::class)->setStrict(true)`. A value set with `setStrict()` takes precedence over the config value.

In strict mode these transitions are allowed:

| From | To |
| --- | --- |
| `Subscriber` | `Lead`, `MarketingQualifiedLead`, `Other` |
| `Lead` | `MarketingQualifiedLead`, `SalesQualifiedLead`, `Other` |
| `MarketingQualifiedLead` | `SalesQualifiedLead`, `Opportunity`, `Lead`, `Other` |
| `SalesQualifiedLead` | `Opportunity`, `MarketingQualifiedLead`, `Lead`, `Other` |
| `Opportunity` | `Customer`, `SalesQualifiedLead`, `Lead`, `Other` |
| `Customer` | `Evangelist`, `Other` |
| `Evangelist` | `Customer`, `Other` |
| `Other` | any stage |

`Customer` to `Lead` is not in the graph, so in strict mode a churn source alone isn't enough. Add the edge or use `force`.

### Customizing the state machine

`LifecycleStateMachine` is a container singleton. Configure it in a service provider's `boot()` method:

```php
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Support\LifecycleStateMachine;
use Illuminate\Database\Eloquent\Model;

$machine = app(LifecycleStateMachine::class);

$machine->allowTransition(LifecycleStage::Subscriber, LifecycleStage::Customer);

$machine->registerGuard('requires_owner', function (Model $record, ?LifecycleStage $from, LifecycleStage $to, string $source): bool {
    return $to !== LifecycleStage::Opportunity || $record->getAttribute('owner_id') !== null;
});
```

A guard receives the record, the current stage, the target stage, and the source. Returning `false` blocks the transition with the message "Lifecycle stage transition guard [requires_owner] failed for record [id]." Any other return value allows it. Registering a guard under an existing name replaces it.

Other methods: `canTransition(LifecycleStage $from, LifecycleStage $to): bool`, `allowedTransitions(LifecycleStage $from): array`, and `isStrict(): bool`.

## Transition history

`Odden\Core\Models\LifecycleStageTransition` (table `odden_lifecycle_stage_transitions`) stores `record_type`, `record_id`, `from_stage`, `to_stage`, `duration_seconds`, `source`, `user_id`, `team_id` (copied from the record), and `transitioned_at`. It has `record()` and `user()` relations and these helpers:

- `durationInDays(): ?float`, rounded to 2 decimals.
- `durationInHours(): ?float`, rounded to 1 decimal.
- `formattedDuration(): string`, such as `3 days`, `1 hour`, `5 mins`, or `< 1 min`.

The `HasLifecycleStageTransitions` trait on `Contact` and `Company` adds:

```php
$contact->lifecycleTransitions;            // newest first
$contact->latestLifecycleTransition();     // ?LifecycleStageTransition
$contact->timeInCurrentStageSeconds();     // int
$contact->timeInCurrentStageDays();        // float
$contact->formattedTimeInCurrentStage();   // "12 days"
```

Time in the current stage is measured from the latest transition, or from `created_at` if there isn't one.

## Funnel velocity

`CalculateFunnelVelocityAction` aggregates transition durations for one model class:

```php
use Odden\Core\Actions\CalculateFunnelVelocityAction;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;

$metrics = app(CalculateFunnelVelocityAction::class)->execute(
    Contact::class,
    fromStage: LifecycleStage::Lead,
    toStage: LifecycleStage::MarketingQualifiedLead,
    startDate: now()->subDays(90),
);
```

`execute(string $recordClass, ?LifecycleStage $fromStage = null, ?LifecycleStage $toStage = null, ?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null, ?int $teamId = null): array` returns:

| Key | Value |
| --- | --- |
| `total_transitions` | Number of matching transitions. |
| `average_duration_seconds` | Float. |
| `average_duration_days`, `median_duration_days`, `min_duration_days`, `max_duration_days` | Floats, rounded to 2 decimals. |
| `transitions_by_stage` | A list of `from_stage`, `to_stage`, `count`, `avg_days`, and `formatted_avg_duration` per pair of stages. |

The dates filter on `transitioned_at`. All values are `0` and `transitions_by_stage` is empty when nothing matches.
