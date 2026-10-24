---
title: The action registry
description: One list of the things the CRM can do, run through one executor that validates, checks permission, holds risky requests for approval, and logs every run.
---

The action registry describes what the CRM can do (find a contact, add a note, move a deal) once, with a name, the input it takes, and how risky it is. Anything that acts on the CRM, such as a script, an integration, or an AI assistant add-on, runs actions through `ActionExecutor`, so validation, permission checks, approval and the audit log happen in one place.

An action wraps the existing Action classes (`CreateContactAction`, `ChangeDealStageAction`); it does not replace them.

## Running an action

```php
use Odden\Core\Registry\ActionExecutor;
use Odden\Core\Registry\Actor;

$result = app(ActionExecutor::class)->run('contacts.create', Actor::user(auth()->id()), [
    'email' => 'ann@example.com',
    'first_name' => 'Ann',
], via: 'api');

$result->status; // completed, proposed or failed
$result->data;   // what the action returned
```

`run()` never throws for bad input or a refusal: it returns a `failed` result with a message and per-field `errors`. Keys the action does not name are dropped. A repeat call with the same `idempotencyKey` returns the earlier completed run instead of doing it again.

## Built-in actions

| Name | Risk | What it does |
| :--- | :--- | :--- |
| `contacts.search` | read | Find contacts by the start of a name, or a whole email address or phone number (25 at most) |
| `contacts.create` | write | Add a contact; returns the existing one if the email is already there |
| `contacts.update` | write | Change name, job title, phone or timezone |
| `notes.add` | write | Add a note to a contact's timeline |
| `tasks.create` | write | Add a task, with an optional due date |
| `deals.search` | read | Find deals, with the stage ids each can move to (Sales) |
| `deals.move_stage` | write | Move a deal to another stage of its pipeline (Sales) |

## Actors and approval

An `Actor` is a person (`Actor::user($id)`), an AI acting for a person (`Actor::ai($id)`), or the system. Read and write actions run at once for everyone. A **destructive** or **outbound** action (one that deletes, or sends something out of the CRM) requested by an AI is not run: it is saved as a `ProposedAction` and `run()` returns `proposed`. A person then calls `approve()` or `reject()`:

```php
$executor->approve($proposal, Actor::user($approverId)); // runs once, as the approver
$executor->reject($proposal, Actor::user($approverId));
```

Proposals expire after 24 hours, can only be decided by a person, and run at most once. The input is stored encrypted.

## Permissions

A self-hosted install lets everyone do everything. A host application rebinds `Odden\Core\Registry\ActionGate` to its own checks; for an AI actor it must answer as the person the AI acts for, so the AI never has more rights than they do.

## The log

Every run, including refused and failed ones, writes a row to `odden_action_log`: the action, who, through what (`via`), the outcome, and the record affected. Fields that hold what people wrote (a note's body) are recorded as `[redacted]`.

## Adding an action

Implement `CrmAction` (extend `Odden\Core\Registry\Actions\BaseAction` for the defaults) and register it from your service provider:

```php
$this->callAfterResolving(ActionRegistry::class, fn (ActionRegistry $registry) => $registry->register(MyAction::class));
```

`rules()` is both the validation and the input schema: `app(ActionRegistry::class)->toolDefinitions()` returns every action as a name, description and JSON schema for a model or an MCP server.
