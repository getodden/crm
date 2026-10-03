---
title: Lead routing
description: Assign owners to new contacts and deals with round robin or quota-weighted routing rules.
---

Lead routing sets `owner_id` on a contact or deal by picking a user from a rule's pool. Rules are stored as `Odden\Sales\Models\LeadRoutingRule` records and applied by `Odden\Sales\Actions\RouteLeadAction`. Routing never runs automatically: call the action where new leads arrive, such as after a form submission or in a `created` model observer.

## Rules

| Attribute | Type | Notes |
| --- | --- | --- |
| `name` | string | Used in the activity note. |
| `strategy` | `LeadRoutingStrategy` | Defaults to `round_robin`. |
| `criteria` | array, nullable | Conditions the record must match. Empty means "match everything". |
| `assigned_user_ids` | array of user IDs | The pool. Rules with an empty pool are skipped. |
| `last_assigned_index` | int | Round-robin position. Defaults to `-1` (nobody assigned yet). |
| `is_active` | bool | Defaults to `true`. |
| `sort_order` | int | Rules are tried in ascending order. |

`Odden\Sales\Enums\LeadRoutingStrategy` has `RoundRobin` (`round_robin`), `QuotaWeighted` (`quota_weighted`), and `Territory` (`territory`), each with a `label()`.

## Routing a record

```php
public function execute(Contact|Deal $target): ?array
```

```php
use Odden\Core\Enums\LeadStatus;
use Odden\Sales\Actions\RouteLeadAction;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Models\LeadRoutingRule;

LeadRoutingRule::create([
    'name' => 'Inbound round robin',
    'strategy' => LeadRoutingStrategy::RoundRobin,
    'criteria' => ['lead_status' => LeadStatus::New->value],
    'assigned_user_ids' => [$alice->id, $bob->id],
    'sort_order' => 10,
]);

$result = app(RouteLeadAction::class)->execute($contact);

if ($result !== null) {
    $result['assigned_user_id']; // The new owner
    $result['rule'];             // The LeadRoutingRule that matched
}
```

The action goes through active rules in `sort_order`. The first rule whose criteria match and whose pool is not empty picks a user. The action then:

- updates the record's `owner_id` (with model events), and
- logs a note activity on the record titled `Lead Routed to {user name}`.

It returns `null`, and changes nothing, if no rule matches. It assigns an owner even if the record already has one.

## Criteria

Criteria are an array of key/value pairs that must all match. The supported keys depend on the record type, and unknown keys are ignored.

| Record | Key | Matches when |
| --- | --- | --- |
| Contact | `lead_status` | The contact's `lead_status` value equals the expected string, for example `'new'`. |
| Contact | `timezone` | The contact's `timezone` equals the expected value. |
| Contact | `city` | The `city` custom property of the contact's first company equals the expected value. |
| Deal | `pipeline_id` | The deal's `pipeline_id` equals the expected ID. |
| Deal | `min_amount` | The deal's `amount` is at least the expected value. |

A key that doesn't apply to the record type (for example `min_amount` on a contact) is ignored, so a rule meant for deals can also match contacts. Use different rules, ordered with `sort_order`, if you route both.

```php
LeadRoutingRule::create([
    'name' => 'Enterprise deals',
    'strategy' => LeadRoutingStrategy::Territory,
    'criteria' => ['pipeline_id' => $pipeline->id, 'min_amount' => 50000],
    'assigned_user_ids' => [$alice->id],
]);

app(RouteLeadAction::class)->execute($deal);
```

## Strategies

### Round robin

`RoundRobin` picks users in rotation. Each assignment advances `last_assigned_index` by one (wrapping at the end of the pool) and assigns the user at the new index. The index starts at `-1` ("nobody assigned yet"), so the first lead of a new rule goes to the first user in the pool, then the second, and so on.

### Territory

`Territory` assigns every matching lead to the first user in `assigned_user_ids`, the territory owner. There is no rotation. A `Territory` rule must have `criteria`; a rule without any is skipped, because it would otherwise match every lead.

### Quota-weighted

`QuotaWeighted` assigns the lead to the pool member who is furthest behind on quota. For each user it looks up their most recent [`SalesQuota`](health-and-forecasting.md#quotas) whose period includes today, then picks:

1. the lowest `attainment_percent`,
2. on a tie, the largest `gap_to_target`,
3. on a further tie, the earliest position in the pool.

Users with no current quota count as 0% attainment with no gap, so they are favored over users who have made progress. The chosen user's position is stored in `last_assigned_index`.
