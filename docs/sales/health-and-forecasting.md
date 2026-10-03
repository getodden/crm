---
title: Deal health, forecasts, and quotas
description: Detect rotting deals, score deal health, and calculate pipeline forecasts, stage velocity, and quota attainment.
---

The sales package calculates its metrics on demand. Nothing is cached or stored, and no scheduled job is involved: each method or action below runs its queries when you call it. The forecast, stage velocity, and quota calculations accept an optional `$teamId` that limits them to deals with that `team_id`; leave it `null` to include every team.

## Rotting deals

A stage's `rot_after_days` sets how long an open deal may sit in it. Two methods on `Deal` use it:

- `daysInCurrentStage(): int`: whole days since the deal entered its current stage (from the newest [stage history](deals.md#stage-history) row, or `created_at` if there is none).
- `isRotten(): bool`: `true` when the deal is open, its stage has a `rot_after_days` greater than zero, and `daysInCurrentStage()` is at least that number.

```php
if ($deal->isRotten()) {
    $deal->logTask(
        title: "Unblock {$deal->name}",
        dueAt: now()->addDay(),
    );
}
```

There is no built-in command that flags or notifies on rotting deals. Rotting status feeds into the health score, the forecast's `stale_deals_count`, and stage velocity's `stale_deal_count`.

## Health score

`$deal->getHealthScore()` (backed by `Odden\Sales\Actions\CalculateDealHealthScoreAction`) returns a score from 0 to 100 with an explanation:

```php
$health = $deal->getHealthScore();

$health['score'];           // int
$health['status'];          // 'strong', 'moderate', 'at_risk', or 'stalled'
$health['badge_color'];     // 'success', 'info', 'warning', or 'danger'
$health['badge_label'];     // Display label, for example "At Risk (42)" with a leading emoji
$health['factors'];         // list of ['name', 'points', 'positive', 'description']
$health['recommendations']; // list of strings
```

Won deals always score 100 (`strong`) and lost deals 0 (`stalled`). Open deals start at 50 and are adjusted:

| Factor | Points |
| --- | --- |
| Two or more associated contacts | +20 |
| One associated contact | +10 |
| No associated contacts | −15 |
| Latest activity 5 days ago or less | +20 |
| Latest activity 6 to 12 days ago | +5 |
| Latest activity more than 12 days ago | −25 |
| No activities | −10 |
| Deal is rotting | −30 |
| Deal is not rotting | +10 |
| Latest quote is `accepted` | +30 |
| Latest quote is `sent` | +15 |
| Expected close date is today or earlier | −15 |
| Expected close date is in the future | +5 |

The result for open deals is clamped to 5–99. Status is `strong` at 75 or more, `moderate` at 50 or more, `at_risk` at 30 or more, and `stalled` below that.

Any activity on the deal counts toward recency, including activities the package logs itself, such as stage automation tasks and quote portal views.

The sales package ships no view for this array, so it stays free of Filament. The Odden Filament package renders it on the deal page with `odden-filament::deals.health-score-modal` (passed as `$health`); outside Filament, build your own view from the array.

## Pipeline forecast

`$pipeline->forecast()` returns metrics for one pipeline; pass a team ID (`$pipeline->forecast($teamId)`) to count only that team's deals. Call `Odden\Sales\Actions\CalculatePipelineForecastAction::execute(?int $pipelineId = null, ?int $teamId = null)` directly with `null` to cover all pipelines.

```php
use Odden\Sales\Actions\CalculatePipelineForecastAction;

$forecast = $pipeline->forecast();

$all = app(CalculatePipelineForecastAction::class)->execute();
```

| Key | Meaning |
| --- | --- |
| `open_value` | Sum of `amount` for open deals. |
| `open_count` | Number of open deals. |
| `weighted_forecast` | Sum of each open deal's `amount × stage probability / 100`. |
| `won_value` | Sum of `amount` for won deals. |
| `won_count` | Number of won deals. |
| `lost_count` | Number of lost deals. |
| `win_rate` | `won / (won + lost) × 100`, one decimal. `0.0` when nothing is closed. |
| `average_deal_size` | Average `amount` across all deals, including open and lost ones. |
| `lost_reasons` | Count of lost deals per `lost_reason`, with `unspecified` for empty reasons. |
| `stale_deals_count` | Number of open deals that are rotting. |

All values are all-time; there is no date filter. Soft-deleted deals are excluded.

## Stage velocity

`Odden\Sales\Actions\CalculateStageVelocityAction::execute(?int $pipelineId = null, ?int $teamId = null)` reports time spent per stage:

```php
use Odden\Sales\Actions\CalculateStageVelocityAction;

$velocity = app(CalculateStageVelocityAction::class)->execute($pipeline->id);

$velocity['average_sales_cycle_days']; // float

foreach ($velocity['stages'] as $stageId => $metrics) {
    $metrics['stage_name'];
    $metrics['probability'];
    $metrics['average_dwell_days'];  // Average time in the stage for deals that have left it
    $metrics['transition_count'];    // How many times deals entered the stage
    $metrics['stale_deal_count'];    // Open deals in the stage past rot_after_days
}
```

`average_sales_cycle_days` is the average number of days from `created_at` to `closed_at` for won deals. `stages` is keyed by stage ID.

## Quotas

An `Odden\Sales\Models\SalesQuota` is a revenue target for one user:

| Attribute | Type | Notes |
| --- | --- | --- |
| `user_id` | int | The rep. |
| `pipeline_id` | int, nullable | Limit the quota to one pipeline, or `null` for all. |
| `period_type` | `QuotaPeriod` | `Monthly`, `Quarterly`, or `Yearly`. Defaults to `monthly`. Informational only. |
| `period_start`, `period_end` | date | The period that is measured. |
| `target_amount` | decimal | |
| `currency` | string(3) | Defaults to `USD`. Not used in calculations. |

`Odden\Sales\Actions\CalculateQuotaAttainmentAction::execute(SalesQuota $quota, ?int $teamId = null)` measures it (with a team ID, only that team's deals count toward the quota):

```php
use Odden\Sales\Actions\CalculateQuotaAttainmentAction;
use Odden\Sales\Enums\QuotaPeriod;
use Odden\Sales\Models\SalesQuota;

$quota = SalesQuota::create([
    'user_id' => $rep->id,
    'pipeline_id' => $pipeline->id,
    'period_type' => QuotaPeriod::Quarterly,
    'period_start' => now()->startOfQuarter(),
    'period_end' => now()->endOfQuarter(),
    'target_amount' => 40000,
]);

$attainment = app(CalculateQuotaAttainmentAction::class)->execute($quota);
```

| Key | Meaning |
| --- | --- |
| `target_amount` | The quota's target. |
| `won_amount` | Sum of `amount` for won deals owned by the user with `closed_at` inside the period. |
| `attainment_percent` | `won_amount / target × 100`, one decimal. |
| `gap_to_target` | `target − won_amount`, never below zero. |
| `open_pipeline_amount` | Sum of `amount` for the user's open deals with `expected_close_date` inside the period. |
| `coverage_ratio` | `(won_amount + open_pipeline_amount) / target`, two decimals. |

Deals count toward the user in `owner_id`. Amounts are summed without currency conversion. Quotas are also used by [quota-weighted lead routing](lead-routing.md#quota-weighted).
