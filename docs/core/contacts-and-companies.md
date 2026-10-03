---
title: Contacts and companies
description: The Contact and Company models, the actions that create them, corporate-domain auto-association, company enrichment, and customer health scores.
---

`Odden\Core\Models\Contact` and `Odden\Core\Models\Company` are the two built-in CRM records. Both use soft deletes, [custom properties](custom-properties.md) with change history, [associations](associations.md), [activities](activities.md), [lifecycle stages](lifecycle-stages.md), and the `forTeam()` scope.

## Contacts

The `odden_contacts` table has these columns:

| Column | Type | Default |
| --- | --- | --- |
| `first_name`, `last_name`, `job_title` | string, nullable | |
| `email` | string, required, indexed | |
| `phone`, `linkedin_url`, `timezone` | string, nullable | |
| `lifecycle_stage` | `LifecycleStage` enum | `lead` |
| `became_<stage>_at` | datetime, one per stage | `null` |
| `lead_status` | `LeadStatus` enum | `new` |
| `lead_score` | integer | `0` |
| `lead_score_updated_at`, `last_contacted_at` | datetime, nullable | |
| `properties` | JSON, cast to array | `null` |
| `owner_id` | foreign key to your user table | `null` |
| `team_id` | unsigned big integer, nullable | `null` |

Helpers on `Contact`:

- `full_name`: `first_name` and `last_name` joined, falling back to `email` when both are empty.
- `became_mql_at` and `became_sql_at`: read-only aliases for `became_marketing_qualified_lead_at` and `became_sales_qualified_lead_at`.
- `markContacted(?CarbonInterface $at = null)`: sets `last_contacted_at` (default `now()`) with a normal `update()`, so model events fire and the change is written to the [property history](custom-properties.md#change-history).
- `owner()`: `BelongsTo` your user model (see [the user model](integration.md#the-user-model)).
- `companies()`: `BelongsToMany` companies through `odden_associations`, where the contact is the parent and the company the child, of any association type. The pivot includes `id` and `type`.
- `whereEmail(string $email)` scope: lowercases and trims the value before matching. It compares the stored value exactly, so it won't find a contact saved with mixed case; use `ContactLookup` below for that.

### Looking up contacts by email

`Odden\Core\Support\ContactLookup` finds and creates contacts by email address, ignoring case and surrounding whitespace. The Service portal, chat widget, and inbound email, and Sales meeting booking all use it, so one person is one contact however they type their address.

- `ContactLookup::normalizeEmail(string $email): string` lowercases and trims.
- `ContactLookup::findByEmail(string $email): ?Contact` first looks for a contact stored with exactly the normalized address, which can use the `email` index. Only when there's none does it compare `LOWER(TRIM(email))`, which also matches older contacts saved with mixed case or spaces (this query can't use the index). Among several matches, the oldest wins. An empty address returns `null`.
- `ContactLookup::findOrCreate(string $email, array $attributes = []): Contact` returns the match, or creates a contact with the given attributes and the normalized email.

```php
use Odden\Core\Support\ContactLookup;

$contact = ContactLookup::findOrCreate(' Dana@Example.com', ['first_name' => 'Dana']);
$contact->email; // "dana@example.com" for a new contact
```

`Contact` also uses Laravel's `Notifiable` trait. The subscription helpers that depend on Marketing's columns live in Marketing, as `Odden\Marketing\Support\ContactPreferences::isSubscribedToTopic()` and `preferenceCenterUrl()`.

`$contact->companies()` and `$company->contacts()` qualify plain column names when you `pluck()` through them, so `$contact->companies()->pluck('id')` works even though the association table has its own `id`.

### Lead status

`Odden\Core\Enums\LeadStatus` is a sales-qualification status, separate from the lifecycle stage. Each case has `label()` and `color()` (a Filament color name).

| Case | Value | `label()` |
| --- | --- | --- |
| `New` | `new` | New Lead |
| `Open` | `open` | Open |
| `InProgress` | `in_progress` | In Progress |
| `AttemptedContact` | `attempted_contact` | Attempted Contact |
| `Connected` | `connected` | Connected |
| `BadTiming` | `bad_timing` | Bad Timing |
| `Unqualified` | `unqualified` | Unqualified |

## Companies

The `odden_companies` table has these columns:

| Column | Type | Default |
| --- | --- | --- |
| `name` | string, required | |
| `domain`, `phone`, `industry` | string, nullable | |
| `lifecycle_stage` | `LifecycleStage` enum, nullable | `lead` |
| `became_<stage>_at` | datetime, one per stage | `null` |
| `account_tier` | string, nullable | `null` |
| `intent_score` | unsigned integer | `0` |
| `intent_surge` | boolean | `false` |
| `buying_committee_size` | unsigned integer | `0` |
| `last_intent_activity_at` | datetime, nullable | |
| `health_score` | unsigned small integer | `70` |
| `health_status` | `CustomerHealthStatus` enum | `healthy` |
| `last_health_calculated_at` | datetime, nullable | |
| `properties`, `owner_id`, `team_id` | as on contacts | |

Helpers on `Company`:

- `isHealthy()` and `isAtRisk()`: compare `health_status`.
- `isTargetAccount()`: `true` when `account_tier` is `tier_1` or `tier_2`.
- `isSurging()`: returns `intent_surge`.
- `became_mql_at`, `became_sql_at`, `owner()`: as on `Contact`.
- `contacts()`: the inverse of `Contact::companies()`.
- `whereDomain(string $domain)` scope: lowercases and trims the value before matching.

Database defaults are not loaded into a model you just created. Call `fresh()` if you need `health_status` or `lifecycle_stage` right after `create()`.

When you query through `companies()` or `contacts()`, qualify column names that also exist on the associations table, such as `id`. For example, use `->get()->modelKeys()` instead of `->pluck('id')`.

## Creating records

Use the actions rather than `Model::create()`. Only the actions normalize input and dispatch `ContactCreated` and `CompanyCreated`.

```php
use Odden\Core\Actions\CreateCompanyAction;
use Odden\Core\Actions\CreateContactAction;
use Odden\Core\Enums\LeadStatus;

$company = app(CreateCompanyAction::class)->execute([
    'name' => 'Acme Corp',
    'domain' => 'https://Acme.com/',
    'industry' => 'Manufacturing',
]);

$contact = app(CreateContactAction::class)->execute([
    'first_name' => 'Jane',
    'last_name' => 'Doe',
    'email' => ' Jane@Acme.com ',
    'lifecycle_stage' => 'lead',
    'lead_status' => LeadStatus::New,
    'properties' => ['annual_budget' => 150000],
], autoAssociateCompany: true);

$company->domain;                    // "acme.com"
$contact->email;                     // "jane@acme.com"
$contact->companies()->first();      // Acme Corp, association type "primary"
```

`CreateContactAction::execute(array $attributes, bool $autoAssociateCompany = false, bool $createCompanyIfMissing = false): Contact`

- lowercases and trims `email`.
- converts a string `lifecycle_stage` to `LifecycleStage`. An invalid value throws `ValueError`.
- creates the contact and dispatches `ContactCreated`.
- runs domain auto-association when `$autoAssociateCompany` is `true` or `odden-core.auto_associate_companies` is `true`.

`CreateCompanyAction::execute(array $attributes, bool $enrich = false): Company`

- lowercases and trims `domain`, and strips a leading `http://` or `https://` and any trailing `/`. It does not strip `www.` or a path.
- creates the company and dispatches `CompanyCreated`.
- runs [enrichment](#enrichment) when `$enrich` is `true` or `odden-core.enrichment.auto_enrich` is `true`.

## Domain auto-association

`AutoAssociateContactCompanyAction` links a contact to the company whose `domain` matches the contact's email domain:

```php
use Odden\Core\Actions\AutoAssociateContactCompanyAction;

use Odden\Core\Models\Contact;

$contact = Contact::create(['email' => 'sam@mail.globex-corp.com']);

$company = app(AutoAssociateContactCompanyAction::class)->execute($contact, createCompanyIfMissing: true);

$company->name;   // "Globex Corp"
$company->domain; // "globex-corp.com"
```

`execute(Contact $contact, bool $createCompanyIfMissing = false, string $associationType = 'primary'): ?Company` does the following:

1. Extracts the corporate domain with `ExtractCorporateDomainAction`. It returns `null` if the email is invalid or uses a freemail domain.
2. Returns the company if the contact is already associated with a company that has this domain.
3. Otherwise looks up a company by `domain`. If the contact has a `team_id`, only companies with the same `team_id` or no team match. If one is found, associates the contact (as parent) with it using the `primary` association type.
4. If no company is found and `$createCompanyIfMissing` is `true`, creates one through `CreateCompanyAction`, copying the contact's `team_id` and `owner_id`, and associates it. The name is derived from the domain: `globex-corp.com` becomes `Globex Corp`. Otherwise it returns `null`.

Through `CreateContactAction` this runs with `createCompanyIfMissing` as passed to that action, which defaults to `false`.

### Corporate domain extraction

`ExtractCorporateDomainAction::execute(string $email): ?string` lowercases the address and validates it. It strips a leading `www.` and one common mail-server prefix (`mail.`, `email.`, `smtp.`, `webmail.`, `mx.`, `exchange.`, `pop.`, `imap.`) when at least two dots remain. It returns `null` for freemail domains.

```php
use Odden\Core\Actions\ExtractCorporateDomainAction;

$domains = app(ExtractCorporateDomainAction::class);

$domains->execute('ann@gmail.com');      // null
$domains->execute('ann@www.initech.io'); // "initech.io"
```

`Odden\Core\Support\FreemailDomains` holds a built-in list of about 60 consumer providers (`gmail.com`, `outlook.com`, `icloud.com`, regional ISPs, and so on). Add your own with `odden-core.freemail_domains`. The values are compared exactly, so write them in lowercase:

```php
// config/odden-core.php
'freemail_domains' => ['example-isp.net'],
```

`FreemailDomains::isFreemail(string $domain): bool` checks both lists. `FreemailDomains::all()` returns only the built-in list.

## Enrichment

`EnrichCompanyAction::execute(Company $company, ?string $driverName = null): Company` asks an enrichment driver about the company's `domain` and saves the result. It does nothing if the company has no domain or the driver returns `null`. Otherwise it:

- sets `industry` if it is empty,
- merges `logo_url`, `tech_stack`, `employee_count_range`, `description`, `city`, `country`, `linkedin_url` (whichever the driver returned) and `enriched_at` into `properties`,
- saves normally, so model events fire and the changes are written to the [property history](custom-properties.md#change-history),
- dispatches `CompanyEnriched` with the raw driver data.

```php
$company = app(CreateCompanyAction::class)->execute(
    ['name' => 'QuickPay', 'domain' => 'quickpay.com'],
    enrich: true,
);

$company->industry;                              // "Financial Services & FinTech"
$company->getProperty('employee_count_range');   // "11-50"
```

The default `heuristic` driver (`HeuristicEnrichmentDriver`) makes no HTTP requests. It guesses from the domain name alone: the industry and tech stack come from keywords in the domain, `employee_count_range` is always `11-50`, `logo_url` is a Google favicon URL, and `description` is generated text. Treat its output as placeholder data.

### Custom drivers

Implement `Odden\Core\Contracts\EnrichmentDriver` and register it on the `EnrichmentManager` singleton, typically in a service provider's `boot()` method:

```php
use Odden\Core\Contracts\EnrichmentDriver;
use Odden\Core\Support\Enrichment\EnrichmentManager;

class ClearbitDriver implements EnrichmentDriver
{
    public function enrich(string $domain): ?array
    {
        // Call your provider. Return null when nothing is found.
        return ['industry' => 'Software', 'city' => 'Berlin'];
    }
}

app(EnrichmentManager::class)->extend('clearbit', fn () => new ClearbitDriver);
```

`extend()` accepts a driver instance or a closure that returns one. Select it with `ODDEN_ENRICHMENT_DRIVER=clearbit`, or pass the name per call: `app(EnrichCompanyAction::class)->execute($company, 'clearbit')`. An unknown driver name throws `InvalidArgumentException`.

```env
ODDEN_ENRICHMENT_DRIVER=heuristic
```

## Customer health scores

`CalculateCustomerHealthScoreAction::execute(Company $company): Company` computes a score from 0 to 100, saves `health_score`, `health_status`, and `last_health_calculated_at`, and returns the refreshed company. Within Core, only [merging companies](duplicates-and-merging.md#merging-companies) calls it. The Filament package adds a button that runs it on demand. Nothing recalculates scores on a schedule, so schedule it yourself if you want them kept current.

```php
use Odden\Core\Actions\CalculateCustomerHealthScoreAction;

$company = app(CalculateCustomerHealthScoreAction::class)->execute($company);

// With a note logged today and three associated contacts:
$company->health_score;  // 95 (70 + 15 + 10)
$company->health_status; // CustomerHealthStatus::Healthy
```

The score starts at 70 and is adjusted as follows:

| Signal | Adjustment |
| --- | --- |
| Latest activity on the company within 14 days | +15 |
| Latest activity more than 14 and up to 30 days ago | +5 |
| Latest activity 60 or more days ago | -20 |
| No activities at all | -10 |
| 3 or more associated contacts (`contacts()`) | +10 |
| No associated contacts | -10 |
| A deal with status `won` / `open` / `lost` in the last 30 days | +15 / +10 / -10 |
| Each open `high` or `urgent` ticket | -15, up to -30 |
| Each ticket with an SLA breach | -20, up to -40 |
| Average CSAT of 4.0 or more / 2.5 or less | +15 / -25 |
| No CSAT ratings, all tickets resolved, no breaches | +10 |

Only activities logged directly on the company count, not activities rolled up from associated contacts.

The deal and ticket signals use the company's `deals` and `tickets` relations. Core doesn't define them: Sales and Service register them with `resolveRelationUsing()`, and the action detects them with `isRelation()`, so it finds relations declared as methods or registered at boot. Without Sales the deal signals are skipped, and without Service the ticket signals are skipped. In that case the lowest possible score is 40, so a company can only reach `AtRisk` once at least one of those packages is installed. Deals count by their `status` (`won`, `open`, `lost`, from a string or backed enum). Tickets use `status`, `priority`, `is_sla_response_breached`, `is_sla_resolution_breached`, and `csat_rating`.

`SummarizeTimelineAction` uses the same relations for its deal and ticket counts.

The status is `Healthy` at 70 or above, `Neutral` from 40 to 69, and `AtRisk` below 40.

When a company moves into `AtRisk` from another status, the action also logs a pending task on the company, "Customer Churn Risk Alert: {name}", due in 24 hours.

`Odden\Core\Enums\CustomerHealthStatus` cases:

| Case | Value | `label()` | `color()` | `badgeIcon()` |
| --- | --- | --- | --- | --- |
| `Healthy` | `healthy` | Healthy | `success` | `heroicon-m-check-circle` |
| `Neutral` | `neutral` | Neutral | `warning` | `heroicon-m-minus-circle` |
| `AtRisk` | `at_risk` | At Risk | `danger` | `heroicon-m-exclamation-triangle` |

## Team scoping

Contacts and companies have a nullable `team_id` and the `forTeam()` scope:

```php
$contacts = Contact::forTeam(1)->get();
```

No global scope is applied. Every query returns all teams unless you add `forTeam()` yourself.
