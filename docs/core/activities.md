---
title: Activities and the timeline
description: Log notes, calls, tasks, and other activities on CRM records, read a record's timeline including associated records, and generate a timeline summary.
---

An activity is a timeline entry attached to one record, its subject. Activities are stored in `odden_activities` as `Odden\Core\Models\Activity`. Contacts, companies, and custom object records get logging helpers and a timeline from the `HasActivities` trait.

## The Activity model

| Column | Notes |
| --- | --- |
| `subject_type`, `subject_id` | The record. `subject()` is a `MorphTo` relation. |
| `type` | `ActivityType` enum, default `note`. |
| `status` | `ActivityStatus` enum, default `completed`. |
| `title`, `body` | Nullable. |
| `due_at`, `completed_at` | Nullable datetimes. |
| `creator_id` | Your user model. `creator()` is a `BelongsTo` relation. |
| `metadata` | JSON, cast to array. |

`Odden\Core\Enums\ActivityType`:

| Case | Value | `label()` |
| --- | --- | --- |
| `Note` | `note` | Note |
| `Call` | `call` | Phone Call |
| `Email` | `email` | Email |
| `Meeting` | `meeting` | Meeting |
| `Task` | `task` | Task |
| `LinkedIn` | `linkedin` | LinkedIn / Social |
| `WhatsApp` | `whatsapp` | WhatsApp |
| `Sms` | `sms` | SMS |
| `StageChange` | `stage_change` | Stage Change |
| `SystemEvent` | `system_event` | System Event |

`Odden\Core\Enums\ActivityStatus`: `Pending` (`pending`), `InProgress` (`in_progress`), `Completed` (`completed`), `Cancelled` (`cancelled`), each with a `label()`.

Methods that accept `ActivityType|string` or `ActivityStatus|string` only accept the backing values above. Any other string, such as `'fax'`, throws `ValueError`. Put channel-specific detail in `metadata`.

## Logging from a record

```php
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;

$contact->logNote('Prefers email over phone.');

$contact->logCall('Discovery call', 'Budget confirmed for Q3.', ['duration_seconds' => 940]);

$contact->logTask('Send proposal', now()->addDays(2));

$contact->logActivity(
    type: ActivityType::Meeting,
    title: 'Onsite demo',
    status: ActivityStatus::Pending,
    dueAt: now()->addWeek(),
);
```

| Method | Creates |
| --- | --- |
| `logActivity(ActivityType\|string $type, string $title, ?string $body = null, array $metadata = [], ActivityStatus\|string $status = ActivityStatus::Completed, ?CarbonInterface $dueAt = null, ?int $creatorId = null): Activity` | Any activity. |
| `logNote(string $body, ?string $title = null, ?int $creatorId = null): Activity` | A completed `Note`, titled "Note added" by default. |
| `logCall(string $title, ?string $body = null, array $metadata = [], ?int $creatorId = null): Activity` | A completed `Call`. |
| `logTask(string $title, ?CarbonInterface $dueAt = null, ?string $body = null, ?int $creatorId = null): Activity` | A `Pending` `Task`. |

For all of them:

- `completed_at` is set to `now()` when the status is `Completed`, and `null` otherwise.
- `creator_id` defaults to `auth()->id()`.
- `ActivityLogged` is dispatched. The helpers call `LogActivityAction`, so there is a single logging path.

## Logging with the action

`LogActivityAction` works on any model, including ones without the trait, and dispatches `ActivityLogged`. The trait methods above call it:

```php
use Odden\Core\Actions\LogActivityAction;
use Odden\Core\Enums\ActivityType;

$activity = app(LogActivityAction::class)->execute(
    subject: $contact,
    type: ActivityType::Email,
    title: 'Sent pricing sheet',
    metadata: ['message_id' => 'abc123'],
    creatorId: auth()->id(),
);
```

`execute(Model $subject, ActivityType|string $type, string $title, ?string $body = null, array $metadata = [], ActivityStatus|string $status = ActivityStatus::Completed, ?CarbonInterface $dueAt = null, ?int $creatorId = null): Activity`

`creatorId` defaults to `auth()->id()` here too, so pass it explicitly only when the author isn't the signed-in user (for example in queued jobs).

## Reading a record's activities

`activities()` is a `MorphMany` of the activities logged directly on the record, newest first:

```php
$recent = $contact->activities()->limit(10)->get();
$openTasks = $contact->activities()->where('status', 'pending')->get();
```

## The timeline

`timeline(bool $includeAssociated = true)` returns an `Activity` query builder, newest first. It includes the record's own activities, and by default the activities of every record [associated](associations.md) with it in either direction. A company's timeline therefore includes notes and calls logged on its contacts.

```php
$company->logNote('Renewal pending.');
$contact->logCall('Check-in call'); // $contact is associated with $company

$company->timeline()->count();                          // 2
$company->timeline(includeAssociated: false)->count();  // 1

$page = $company->timeline()->with('creator')->paginate(25);
```

The rollup is one level deep. Activities on records associated with the associated records are not included.

## Timeline summaries

`SummarizeTimelineAction::execute(Contact|Company $subject): array` builds a rule-based briefing from the record's 15 most recent direct activities, its health or lead score, and its deals and tickets. It calls no external API.

Ask the container for the `Odden\Core\Contracts\SummarizesTimeline` contract, not the class: `SummarizeTimelineAction` is its default implementation, and an application can [bind another one](../filament/customizing.md#swapping-a-built-in-behaviour) that returns the same shape.

```php
use Odden\Core\Contracts\SummarizesTimeline;

$briefing = app(SummarizesTimeline::class)->execute($company);
```

It returns:

| Key | Value |
| --- | --- |
| `title` | `"Odden Breeze Briefing: {name}"` |
| `sentiment` | `positive`, `neutral`, or `at_risk` |
| `executive_summary` | A sentence or two built from templates. |
| `key_milestones` | Up to 5 strings like `"[call] Check-in call (2 hours ago)"`. |
| `recommended_next_action` | One of a fixed set of recommendations. |
| `touchpoints_analyzed` | Number of activities read (at most 15). |

The sentiment is `positive` when a company's `health_score` is 75 or more, or the lead score is 70 or more. For a contact that's `lead_score`, and for a company `intent_score`. It's `at_risk` when a company's health score is below 40, and `neutral` otherwise. Deal and ticket signals only apply when `method_exists($subject, 'deals')` or `method_exists($subject, 'tickets')` is true.
