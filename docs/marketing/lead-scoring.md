---
title: Lead scoring
description: Score contacts on marketing engagement, override the default points with rules, decay inactive scores, hand qualified leads to sales, and calculate account-level intent.
---

Every contact has a `lead_score` column (owned by `getodden/crm-core`). The marketing package changes it through scoring events: a form submission, an email click, a high-intent pageview, and so on. Each change is logged, and crossing a threshold moves the contact's lifecycle stage from lead to marketing qualified lead (MQL) to sales qualified lead (SQL). Reaching SQL can hand the contact to sales automatically.

## Scoring events

`Odden\Marketing\Enums\LeadScoringEventType` lists the events. The default points apply when no [rule](#scoring-rules) overrides them.

| Case | Value | Default points | Applied by |
| --- | --- | --- | --- |
| `FormSubmission` | `form_submission` | 15 | [Form submissions](forms-and-landing-pages.md#what-happens-on-submission), [inbound leads](inbound-webhooks.md#external-lead-webhook) |
| `EmailOpened` | `email_opened` | 3 | Campaign open tracking (see [campaigns](campaigns.md)) |
| `EmailClicked` | `email_clicked` | 10 | Campaign click tracking |
| `Unsubscribed` | `unsubscribed` | −50 | Unsubscribes (see [subscriptions and compliance](subscriptions-and-compliance.md)) |
| `InactivityDecay` | `inactivity_decay` | −10 | Not applied through scoring; [decay](#score-decay) writes its own log entries |
| `PropertyMatch` | `property_match` | 20 | [High-intent pageviews](web-tracking.md#high-intent-pages); with explicit points for [event registration and attendance](events-and-assets.md) and [asset downloads](events-and-assets.md#gated-assets) |
| `CustomEvent` | `custom_event` | 5 | [Custom behavioral events](inbound-webhooks.md#custom-behavioral-events) |

Other parts of the package (email confirmation, NPS responses, ESP webhooks) also apply events; see their pages.

Each case has a `label()` (and the alias `getLabel()`), for example `Link Clicked in Email`.

## Applying an event

`Odden\Marketing\Actions\ApplyLeadScoringEventAction` applies an event to a contact and returns the refreshed contact:

```php
use Odden\Marketing\Actions\ApplyLeadScoringEventAction;
use Odden\Marketing\Enums\LeadScoringEventType;

$contact = app(ApplyLeadScoringEventAction::class)->execute(
    contact: $contact,
    eventType: LeadScoringEventType::EmailClicked,
    description: 'Clicked pricing link in July newsletter',
);

// Explicit points win over rules and defaults
$contact = app(ApplyLeadScoringEventAction::class)->execute(
    contact: $contact,
    eventType: LeadScoringEventType::CustomEvent,
    description: 'Invited a teammate',
    points: 30,
);
```

Signature: `execute(Contact $contact, LeadScoringEventType $eventType, ?string $description = null, ?array $context = null, ?int $points = null): Contact`. `$context` is accepted but not stored.

Inside a database transaction the action:

1. Picks the points: `$points` if given, else the `score_change` of the first active rule for the event type, else the default.
2. Adds them to `lead_score`, never going below 0.
3. Writes a `LeadScoreLog` row with `event_type`, `event_description` (the description, or the event's label), `score_change`, `score_after`, and the `rule_id` of any matching active rule (even when `$points` overrode it).
4. Updates `lead_score`, `lead_score_updated_at`, and `lifecycle_stage`:
   - at or above `odden-marketing.sales_handoff.sql_score_threshold` (default 100), any stage except `customer` becomes `sales_qualified_lead`;
   - otherwise, at or above `odden-marketing.sales_handoff.mql_score_threshold` (default 50), a `lead` becomes `marketing_qualified_lead`.
5. If the contact was just promoted to SQL and `odden-marketing.sales_handoff.auto_handoff_on_sql` is `true` (the default), runs the [sales hand-off](#sales-hand-off).

Stages are never lowered here, only by [decay](#score-decay).

The package adds a `leadScoreLogs` relation (newest first) to `Contact`:

```php
foreach ($contact->leadScoreLogs as $log) {
    echo "{$log->event_description}: {$log->score_change} (now {$log->score_after})";
}
```

## Scoring rules

An `Odden\Marketing\Models\LeadScoringRule` changes the points for an event type:

```php
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\LeadScoringRule;

LeadScoringRule::create([
    'name' => 'Email clicks are worth more',
    'event_type' => LeadScoringEventType::EmailClicked,
    'score_change' => 25,
]);
```

| Attribute | Notes |
| --- | --- |
| `name`, `description` | For your own reference. |
| `event_type` | A `LeadScoringEventType`. |
| `score_change` | Points to add (negative to subtract). The model defaults it to 5. |
| `is_active` | Defaults to `true`. Inactive rules are ignored. |
| `conditions` | Optional. Limits the rule to events where every condition matches; see [Conditions](#conditions). |

For each event the scoring action takes the first active rule for the event type whose conditions match. Rules with conditions are tried first (in order of `id`), so a specific rule wins over a catch-all rule without conditions, which applies when no conditional rule matches. Rules don't affect calls that pass explicit `points`, such as event registration (10), attendance (20), and asset downloads (the asset's `lead_score_points`). The `logs` relation returns the log entries that referenced the rule.

### Conditions

`conditions` is a list of `{field, operator, value}` entries that must all match, or the shorthand `{"field": value}` map, where each entry is an equality check and an array value means "any of":

```php
LeadScoringRule::create([
    'name' => 'Software companies',
    'event_type' => LeadScoringEventType::FormSubmission,
    'score_change' => 30,
    'conditions' => [
        ['field' => 'company.industry', 'operator' => '=', 'value' => 'Software'],
        ['field' => 'contact.lead_score', 'operator' => '<', 'value' => 80],
    ],
]);

LeadScoringRule::create([
    'name' => 'Pricing or enterprise page',
    'event_type' => LeadScoringEventType::PropertyMatch,
    'score_change' => 25,
    'conditions' => ['context.path' => ['/pricing', '/enterprise']],
]);
```

Fields start with `contact.` or `company.` (an attribute, or if there isn't one a custom property, of the contact or their first company) or `context.` (the `context` array passed to the scoring action, for example `path` for a web visit or `page_url` for an auto-captured form). Operators are `=` (the default), `!=`, `>`, `>=`, `<`, `<=`, `contains`, `starts_with`, `in` and `not_in`; string comparisons ignore case. A condition on a value that is missing never matches.

## Score decay

Scores of contacts who stop engaging decay over time. Run the command daily (see [scheduling](../installation.md#schedule-the-commands)):

```bash
php artisan marketing:decay-lead-scores --days=30 --points=5
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--days` | 30 | Inactivity threshold, and the length of one decay period. |
| `--points` | 5 | Points removed per elapsed period. |

For every contact with a positive score, the command (`Odden\Marketing\Actions\DecayInactiveLeadScoresAction::execute(int $inactivityThresholdDays = 30, int $decayPointsPerPeriod = 5)`) measures inactivity from `lead_score_updated_at`, falling back to `last_contacted_at` and then `created_at`. A contact inactive for at least `--days` loses `floor(days inactive / --days) × --points` points, capped at the current score. For example, with `--days=30 --points=10`, a contact last scored 65 days ago loses 20 points.

Lifecycle stages are lowered when the new score falls below a threshold:

| Stage | Demoted when | To |
| --- | --- | --- |
| `marketing_qualified_lead` | score < `mql_score_threshold` (default 50) | `lead` |
| `sales_qualified_lead` | score < `sql_score_threshold` (default 100) | `marketing_qualified_lead` |
| `lead` | score = 0 | `subscriber` |

Each decay updates `lead_score_updated_at`, so the next decay for that contact waits another full period. It also writes a `LeadDecayLog` (`score_before`, `score_after`, `score_decayed`, `days_inactive`), available through the `leadDecayLogs` relation on `Contact`, and a `LeadScoreLog` with event type `inactivity_decay`. Both demotion thresholds follow the same config values as promotion.

The command prints the number of contacts decayed and the total points deducted.

## Sales hand-off

`Odden\Marketing\Actions\HandoffLeadToSalesAction` runs automatically when scoring promotes a contact to SQL. You can also call it directly:

```php
use Odden\Marketing\Actions\HandoffLeadToSalesAction;

$result = app(HandoffLeadToSalesAction::class)->execute(
    contact: $contact,
    dealName: 'Acme expansion',
    amount: 25000,
);

$result['deal'];  // Odden\Sales\Models\Deal, or null
$result['owner']; // the contact's owner, or null
```

Signature: `execute(Contact $contact, ?string $dealName = null, ?float $amount = null, ?int $pipelineId = null, ?int $ownerId = null): array`. It returns `contact`, `deal`, `owner`, and `task_created` (always `true`). The action:

1. Picks an owner: `$ownerId`, else the contact's current owner, else the next user in a round robin over all users ordered by id. The position is kept in the cache (key `odden-marketing:handoff-owner-index`) and advances once per hand-off, so a cache that is cleared or per-process (the `array` driver) restarts the rotation.
2. Sets `lifecycle_stage` to `sales_qualified_lead`, `lead_status` to `in_progress`, and the owner.
3. If `getodden/crm-sales` is installed and a pipeline with stages exists, creates an open deal in the first stage of `$pipelineId` (or the first pipeline). The name defaults to `MQL Deal: {full name}` and the amount to `odden-marketing.sales_handoff.default_deal_amount`. The deal is associated with the contact and with the contact's first company.
4. Logs a task on the contact, due in one hour.

| Config key | Env | Default |
| --- | --- | --- |
| `odden-marketing.sales_handoff.auto_handoff_on_sql` | `MARKETING_AUTO_HANDOFF_ON_SQL` | `true` |
| `odden-marketing.sales_handoff.mql_score_threshold` | `MARKETING_MQL_THRESHOLD` | `50` |
| `odden-marketing.sales_handoff.sql_score_threshold` | `MARKETING_SQL_THRESHOLD` | `100` |
| `odden-marketing.sales_handoff.default_deal_amount` | `MARKETING_HANDOFF_DEAL_AMOUNT` | `10000.00` |

Because the hand-off runs inside the scoring transaction, any request or job that applies a score (a pageview, a form post) can create a deal and a task.

## Account intent

For account-based marketing, `Odden\Marketing\Actions\CalculateCompanyIntentScoreAction` rolls contact engagement up to the company. The intent columns (`intent_score`, `intent_surge`, `buying_committee_size`, `last_intent_activity_at`, `account_tier`) live on the core `Company` model.

```php
use Odden\Marketing\Actions\CalculateCompanyIntentScoreAction;

$company = app(CalculateCompanyIntentScoreAction::class)->execute($company);

$company->intent_score;          // sum of contacts' lead scores + tier bonus
$company->buying_committee_size; // contacts with a score above 0 or contacted in the last 30 days
$company->intent_surge;          // bool
```

- `intent_score` is the sum of the lead scores of the company's associated contacts, plus 50 for `account_tier` `tier_1` or 25 for `tier_2`.
- `buying_committee_size` counts contacts with a positive lead score or a `last_contacted_at` in the last 30 days.
- `intent_surge` is `true` when the intent score is 75 or more, or the committee has two or more members.
- `last_intent_activity_at` is the most recent `last_contacted_at` within 30 days, or now.

When a company starts surging, a task `ABM Intent Surge: {name}` is logged on it, due in four hours.

The calculation isn't scheduled and doesn't run when a lead score changes. It runs when:

- a [custom behavioral event](inbound-webhooks.md#custom-behavioral-events) is tracked for a contact with a company, after the event has been scored;
- `AutoMatchLeadToCompanyAction` matches a contact to a company, which happens on [form submissions](forms-and-landing-pages.md#what-happens-on-submission) without a `company` field and for contacts created by custom events. It runs before the submission's own points are added.

Run it yourself (for example from a scheduled job) to keep intent current.

### Lead-to-account matching

`Odden\Marketing\Actions\AutoMatchLeadToCompanyAction::execute(Contact $contact): ?Company` finds the company whose `domain` equals the contact's email domain, ignoring common free providers (Gmail, Yahoo, Outlook, iCloud, Proton, and others). When it finds one it:

1. associates the contact with the company (type `member`) if they aren't associated yet;
2. copies the company's `owner_id` to the contact if the contact has no owner;
3. logs a task on the company if it's a target account (`account_tier` `tier_1` or `tier_2`);
4. recalculates the company's intent.

It never creates companies. Core's `AutoAssociateContactCompanyAction` (see [domain auto-association](../core/contacts-and-companies.md#domain-auto-association)) can.
