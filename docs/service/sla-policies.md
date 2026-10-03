---
title: SLA policies and business hours
description: Define first response and resolution targets per priority, count only business hours, and flag and escalate breached tickets.
---

An SLA policy sets two targets for each ticket priority: how long until the first agent response, and how long until the ticket is resolved. Tickets get their deadlines from a policy when they are created, and the `service:check-sla` command flags tickets that miss them.

## The SlaPolicy model

`Odden\Service\Models\SlaPolicy` has these attributes. Targets are in minutes; the defaults are the database column defaults.

| Attribute | Default | Notes |
| :--- | :--- | :--- |
| `name`, `description` | | |
| `is_default` | `false` | The policy attached to new tickets that don't specify one. |
| `is_active` | `true` | Inactive policies are never picked as the default for new tickets. |
| `urgent_first_response_minutes` / `urgent_resolution_minutes` | 60 / 240 | |
| `high_first_response_minutes` / `high_resolution_minutes` | 120 / 480 | |
| `medium_first_response_minutes` / `medium_resolution_minutes` | 240 / 1440 | |
| `low_first_response_minutes` / `low_resolution_minutes` | 480 / 2880 | |
| `only_business_hours` | `false` | Count only time inside business hours. |
| `business_hours_start` / `business_hours_end` | `09:00` / `17:00` | `HH:MM`, in the policy's timezone. |
| `business_days` | `null` | ISO weekday numbers (Monday is `1`, Sunday is `7`). `null` means Monday to Friday. |
| `holidays` | `null` | Dates as `Y-m-d` strings. |
| `timezone` | `UTC` | |

`SlaPolicy::defaultPreset()` returns an attribute array for a default policy with the targets above, so you can create one in a seeder or migration:

```php
use Odden\Service\Models\SlaPolicy;

SlaPolicy::create(SlaPolicy::defaultPreset());
```

Methods:

| Method | Returns |
| :--- | :--- |
| `getFirstResponseMinutesFor(TicketPriority $priority): int` | The first response target for a priority. |
| `getResolutionMinutesFor(TicketPriority $priority): int` | The resolution target for a priority. |
| `calculateDueTime(CarbonInterface $from, int $minutes): CarbonInterface` | The deadline `$minutes` after `$from`, see below. |
| `tickets()` | `HasMany` of tickets using the policy. |

## How tickets get deadlines

When a ticket is created:

1. If `sla_policy_id` is empty, the first policy with `is_default = true` is used. Inactive policies are skipped, so an inactive default is never applied. Keep only one default.
2. If the ticket has a policy and no `first_response_due_at`, both `first_response_due_at` and `resolution_due_at` are set with `calculateDueTime(now(), ...)` using the targets for the ticket's priority.

To use a different policy for a ticket, pass it to `CreateTicketAction` (`slaPolicy: $policy`) or set `sla_policy_id` when calling `Ticket::create()`. You can also set the two `*_due_at` columns yourself; they are then left alone.

Changing a ticket's priority recalculates both deadlines from the policy's targets for the new priority, measured from the ticket's creation time (the SLA clock doesn't restart). A deadline whose target is already met is left alone: `first_response_due_at` once `first_responded_at` is set, and `resolution_due_at` once `resolved_at` is set. Call `$ticket->recalculateSlaDueDates()` yourself after changing `sla_policy_id`, or when you update the priority with `updateQuietly()`.

## Business hours

Without `only_business_hours`, `calculateDueTime()` adds the minutes to the start time.

With it, the clock only runs during business hours in the policy's timezone. Time before opening is moved to opening time, time after closing rolls to the next day, and days that are not in `business_days` or are listed in `holidays` are skipped. The result is converted to your `app.timezone`.

```php
use Carbon\Carbon;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Models\SlaPolicy;

$policy = SlaPolicy::create([
    'name' => 'Business hours (New York)',
    'is_default' => true,
    'only_business_hours' => true,
    'business_hours_start' => '09:00',
    'business_hours_end' => '17:00',
    'business_days' => [1, 2, 3, 4, 5], // ISO weekdays: Monday = 1
    'holidays' => ['2026-12-25'],
    'timezone' => 'America/New_York',
    'high_first_response_minutes' => 120,
    'high_resolution_minutes' => 960,
]);

$due = $policy->calculateDueTime(
    Carbon::parse('2026-12-24 16:00', 'America/New_York'),
    $policy->getFirstResponseMinutesFor(TicketPriority::High),
);

// 60 minutes on Thursday the 24th, then Friday is a holiday and the weekend is skipped:
// due Monday 2026-12-28 at 10:00 New York time (15:00 UTC).
```

Any targets you don't set use the column defaults listed above.

## Recording responses and resolution

The breach flags are also set as the ticket progresses, not only by the scheduled check:

- The first public agent reply sets `first_responded_at` and sets `is_sla_response_breached` to `true` if it came after `first_response_due_at`, or `false` otherwise.
- `Ticket::resolve()` sets `resolved_at` and sets `is_sla_resolution_breached` the same way against `resolution_due_at`.

`isFirstResponseBreached()` and `isResolutionBreached()` on the ticket also report a breach that has happened but has not been flagged yet.

## Checking for breaches

`service:check-sla` runs `CheckSlaBreachesAction::execute()`. Run it often, for example every five minutes, from your scheduler (see [Installation](../installation.md#schedule-the-commands)).

```bash
php artisan service:check-sla
```

The action finds tickets that are not `Resolved` or `Closed` and:

- have no `first_responded_at`, a `first_response_due_at` in the past, and `is_sla_response_breached` still `false`; or
- have no `resolved_at`, a `resolution_due_at` in the past, and `is_sla_resolution_breached` still `false`.

For each one it sets the breach flag (quietly, without model events), so each breach is handled once. Then:

- If the ticket has an owner with a `notify()` method, the owner receives `SlaBreachAlertNotification` by email. The breach type is `first_response` or `resolution`.
- Otherwise the ticket is escalated: its priority goes up one level (`Low` to `Medium`, `Medium` to `High`, `High` to `Urgent`; `Urgent` stays `Urgent`), and an internal `System` note is added saying the ticket breached its first response (or resolution) SLA while unassigned and naming the new priority.

Escalating the priority recalculates the ticket's unmet deadlines for the new priority, measured from creation. A breach flag that is already set stays set.

The action returns the counts, and the command prints them:

```php
use Odden\Service\Actions\CheckSlaBreachesAction;

$counts = app(CheckSlaBreachesAction::class)->execute();
// ['response_breaches' => 2, 'resolution_breaches' => 0]
```

### The breach alert email

`SlaBreachAlertNotification` has the subject `[URGENT SLA BREACH] Ticket #{number}: {subject}` and lists the breach type, priority, subject, and assigned agent. Its button links to the `odden-service.admin_ticket_url` config value (default `/admin/tickets/{id}/edit`, the ticket edit page of the Filament admin when its panel is served at `/admin`), where `{id}` is the ticket ID and relative paths are resolved against the app URL. For a dynamic link, call `SlaBreachAlertNotification::resolveUrlUsing(fn (Ticket $ticket): string => ...)` from a service provider; the callback wins over the config value.
