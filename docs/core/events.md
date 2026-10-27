---
title: Events
description: The events Odden Core dispatches, what triggers each one, and how to listen for them.
---

Core dispatches plain Laravel events from its action classes. They are dispatched synchronously after the write, inside the same request. The merge events are dispatched inside the merge's database transaction, so an exception in a listener rolls the merge back. None of them implement `ShouldBroadcast` or `ShouldQueue`. Make your listener queued if it does slow work, except for merge listeners that move data, which must run inside the transaction.

All events are in `Odden\Core\Events`.

| Event | Public properties | Dispatched by |
| --- | --- | --- |
| `ContactCreated` | `Contact $contact` | `CreateContactAction`, before auto-association runs |
| `CompanyCreated` | `Company $company` | `CreateCompanyAction`, before enrichment runs. Also when auto-association creates a company. |
| `CompanyEnriched` | `Company $company`, `array $enrichmentData` | `EnrichCompanyAction`, when the driver returned data |
| `RecordsAssociated` | `Association $association` | `AssociateRecordsAction` and `associateWith()`, only when a new link is created |
| `ActivityLogged` | `Activity $activity` | `LogActivityAction` |
| `LifecycleStageChanged` | `Model $record`, `LifecycleStageTransition $transition` | `TransitionLifecycleStageAction` |
| `CustomObjectDefinitionCreated` | `CustomObjectDefinition $definition` | `CreateCustomObjectDefinitionAction` |
| `CustomObjectRecordCreated` | `CustomObjectRecord $record` | `CreateCustomObjectRecordAction` |
| `UserMentioned` | `int $mentionedUserId`, `?int $actorId`, `Model $mentionable`, `Model $subject`, `string $excerpt` | `RecordMentionsAction`, once for each person newly picked with "@" in a note or comment. Saving it again with the same people dispatches nothing; adding someone dispatches it for them only. `$mentionable` is the note (an `Activity`) or comment, `$subject` the record it is on, `$excerpt` the start of its text, plain. It is also dispatched when people mention themselves: skip `$mentionedUserId === $actorId` if you do not want that. |
| `ContactsMerged` | `Contact $primary`, `Contact $secondary` | `MergeContactsAction`, inside its transaction, after Core's own data has moved and before the secondary is soft-deleted |
| `CompaniesMerged` | `Company $primary`, `Company $secondary` | `MergeCompaniesAction`, inside its transaction, after Core's own data has moved and before the health score is recalculated and the secondary is soft-deleted |

## What does not dispatch events

Events come only from the actions above. These do not dispatch them:

- `Contact::create()`, `Company::create()`, and factories.
- `logActivity()`, `logNote()`, `logCall()`, and `logTask()` on a record, including the notes the merge actions log (the merge itself dispatches `ContactsMerged` or `CompaniesMerged`) and the churn-risk task the health score action logs.
- Updating `lifecycle_stage` with `update()` or `save()`.

If you need to react to every change regardless of how it was made, use Eloquent model events or observers on the models instead.

## Listening

Register listeners as you would for any Laravel event. With event discovery, type-hint the event in a listener's `handle()` method:

```php
namespace App\Listeners;

use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Events\LifecycleStageChanged;
use Illuminate\Contracts\Queue\ShouldQueue;

class NotifySalesOfNewSql implements ShouldQueue
{
    public function handle(LifecycleStageChanged $event): void
    {
        if ($event->transition->to_stage !== LifecycleStage::SalesQualifiedLead) {
            return;
        }

        // $event->record is the Contact or Company.
    }
}
```

Or register a closure in a service provider:

```php
use Odden\Core\Events\ContactCreated;
use Illuminate\Support\Facades\Event;

Event::listen(function (ContactCreated $event): void {
    $event->contact->logNote('Welcome sequence queued.');
});
```

In tests, use `Event::fake()` and assert on the event classes as usual:

```php
Event::fake([LifecycleStageChanged::class]);

app(TransitionLifecycleStageAction::class)->execute($contact, LifecycleStage::MarketingQualifiedLead);

Event::assertDispatched(LifecycleStageChanged::class);
```
