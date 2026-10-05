---
title: Authorization
description: What the Odden Filament plugin checks before showing or changing data, and how to restrict access with panel access and Laravel policies.
---

The plugin ships no policies, gates, permissions or roles. It checks your Laravel policies the same way Filament resources do: when a policy is registered for an Odden model, the plugin's resources, custom pages, boards and actions respect it. When there is no policy, everything is allowed, so an app without policies works out of the box and any user who can get into the panel can see and change every Odden record.

## Panel access

Filament decides who can sign in to the panel. Outside the `local` environment, your user model must implement `Filament\Models\Contracts\FilamentUser`:

```php
<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements FilamentUser
{
    public function canAccessPanel(Panel $panel): bool
    {
        return str_ends_with($this->email, '@yourcompany.com');
    }
}
```

## Policies on Odden models

Odden's resources are standard Filament resources. When a Laravel policy is registered for a resource's model, Filament checks it for that resource's navigation item, list, create, view, edit and delete pages, and for the built-in create, edit, view, delete, restore and force-delete actions. The plugin uses the same policies for its custom pages, boards and actions (see below).

Odden models live in the packages, so register policies for them explicitly, for example in `AppServiceProvider::boot()`:

```php
use App\Policies\ContactPolicy;
use Odden\Core\Models\Contact;
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::policy(Contact::class, ContactPolicy::class);
}
```

```php
<?php

namespace App\Policies;

use App\Models\User;
use Odden\Core\Models\Contact;

class ContactPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Contact $contact): bool
    {
        return $contact->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Contact $contact): bool
    {
        return $contact->owner_id === $user->id;
    }

    public function delete(User $user, Contact $contact): bool
    {
        return false;
    }
}
```

With this policy, users can only open the view and edit pages of contacts they own (other contacts return a 403), and the delete actions are hidden. The **Playbook**, **Auto-Route** and **Merge** row actions only show on contacts the user owns, and merging is refused because the duplicate would be deleted. The Sales Cockpit's touches and call and meeting logs return a 403 for contacts the user doesn't own.

If `viewAny()` returns `false`, **Contacts** disappears from the navigation and `/admin/contacts` returns a 403. So do the pages that show contact data: Executive Overview, Data Quality, the Sales Cockpit and the Marketing Cockpit.

Policies don't filter the table. With the policy above, users still see every contact in the list. To scope the query, you need your own resource subclass that overrides `getEloquentQuery()` (see [Customizing and extending](customizing.md)).

If you want Filament to fail loudly when a model has no policy, enable Filament's `->strictAuthorization()` on the panel. You then need a policy for every Odden model the plugin registers, and for `Odden\Core\Models\Activity` and `Odden\Sales\Models\SalesSequenceEnrollment`, which the Sales Cockpit and the contact relation managers check.

Each resource's model is listed in [Resources](resources.md).

## How the plugin checks policies

Outside Filament's built-in actions, the plugin checks abilities with `Odden\Filament\Support\OddenAuthorization`, which follows Filament's rules for resources:

- If a policy with a method for the ability is registered for the model, the policy decides.
- If there is no policy, or the policy has no method for that ability, the ability is allowed. A `Gate::before()` callback that returns `false` still denies it, and strict authorization throws instead.
- A user must be signed in. Nothing falls back to a default user: with no authenticated user, every check fails with a 403.

Records that a page or action loads by ID (from a Livewire call or a form field) are looked up inside the plugin resource's `getEloquentQuery()`, which applies Filament's tenant scoping. An ID outside that query returns a 404. The lookups use the plugin's own resource classes in `Odden\Filament\Resources`, so a `getEloquentQuery()` override in your own resource subclass isn't applied to them.

You can use the same helper in your own pages and actions:

```php
use Filament\Actions\Action;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Support\OddenAuthorization;

// A row action that is hidden, and refused, unless the user may update the contact.
Action::make('flag')
    ->authorize(OddenAuthorization::forRecord('update', ContactResource::class))
    ->action(fn (Contact $record) => $record->update(['lead_status' => LeadStatus::Unqualified]));

// In a Livewire method: 404 outside the resource's query, 403 if the policy denies.
$contact = OddenAuthorization::findAndAuthorize(ContactResource::class, Contact::class, $id, 'update');
```

## Custom pages

Each custom page requires `viewAny` on every resource whose data it shows. If any of them is denied, the page is hidden from the navigation, opening it returns a 403, and Livewire requests to an already-open page return a 403. Resources of modules that aren't installed are skipped.

| Page | Requires `viewAny` on |
| --- | --- |
| Executive Overview | `ContactResource`, `CompanyResource`, `DealResource`, `SalesQuotaResource`, and each paid add-on's main resource (`CampaignResource`, `TicketResource`) when it is installed |
| Data Quality | `ContactResource`, `CompanyResource` |
| Sales Cockpit | `ContactResource`, `DealResource`, `QuoteResource`, `SalesSequenceResource` |
| Pipeline Board (`/admin/deals/board`) | `DealResource` |

The page classes implement this with the `Odden\Filament\Pages\Concerns\AuthorizesPageAccess` trait, which overrides `canAccess()`.

## Page and board actions

The Livewire methods that change data authorize the record they change:

| Page | Method | Checks |
| --- | --- | --- |
| Data Quality | `mergeContacts()`, `mergeCompanies()` | `update` on the primary record and `delete` on the duplicate, which the merge soft-deletes |
| Pipeline Board | `moveDeal()` | `update` on the deal |
| Sales Cockpit | `logQuickTouch()`, `openCallModal()`, `saveCallLog()`, `openMeetingModal()`, `saveMeetingLog()` | `update` on the contact; a meeting without a contact needs `create` on `Activity` |
| Sales Cockpit | `advanceEnrollment()` | `update` on the contact and on the `SalesSequenceEnrollment` |
| Sales Cockpit | `completeActivity()` | `update` on the `Activity` |

A denied check returns a 403, and an ID outside the resource's query returns a 404. The paid add-ons (Marketing and Service) check the same abilities for the screens they add; their pages and actions are listed with each add-on.

## Resource actions

The custom row and header actions on the resources use Filament's `->authorize()`. An action the user isn't allowed to run is hidden, and Filament refuses to run it if it is called anyway.

| Resource or page | Actions | Checks |
| --- | --- | --- |
| Contacts | **Playbook**, **Auto-Route** | `update` on the contact. Hidden when `getodden/crm-sales` isn't installed. |
| Contacts, Companies | **Merge** | `update` on the record; `delete` on the selected duplicate (403 if denied) |
| Contacts, Companies | **AI Briefing** | `view` on the record |
| Companies | **Recalculate Health** | `update` on the company |
| Contact relation managers | **Enroll in Cadence**, **Adjust Score** | `update` on the contact |
| Contact relation managers | **Advance Step**, **Unenroll** | `update` on the contact and on the enrollment |
| Activities relation manager | **Complete** | `update` on the activity |
| Deals | **Playbook**, **Auto-Route**; on the view page **Generate Quote**, **Run Playbook**, **Mark Won**, **Mark Lost** | `update` on the deal |
| Quotes | **Accept & Sign** | `update` on the quote (and on the deal, in the deal's Quotes relation manager) |
| Deal Quotes relation manager | **Generate from Products** | `update` on the deal |
| Sales Sequences | **Enroll Contact** | `update` on the sequence and on the selected contact |
| Sales Sequences | **Process Due Cadences** | `update` on every active sequence, because it processes all of them |
| CRM Lists | **Sync** (table) and **Sync Members** (view page) | `update` on the list |

## What isn't covered

- **Data visibility on cockpits and dashboards.** Policies decide who can open a page, not which records it shows. Once a user can open a cockpit or dashboard, its counts, charts and lists cover all records. For example, the Sales Cockpit lets any user who can open it switch to another rep's view. The board columns and the lookups by ID are limited to the resource's `getEloquentQuery()`, but `view` isn't checked per record.
- **Read-only and link actions**, such as the deal health analysis and the read-only previews and exports the paid add-ons add. They are available to anyone who can open the page they're on.

If some panel users must not see everything, don't give them access to a panel that has `OddenPlugin` registered. Either limit who can access the panel with `canAccessPanel()`, or build a separate panel from only the resources you want, with your own subclasses. See [Customizing and extending](customizing.md).
