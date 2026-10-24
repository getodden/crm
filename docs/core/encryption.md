---
title: Encrypting personal data
description: How the CRM encrypts fields that hold personal data at rest, with a key for each tenant, and how to use the casts, the key provider and the search index in your own code.
---

Fields that hold personal data can be stored encrypted, so a stolen database or backup is unreadable without the key. Each tenant has a key of its own, so one tenant's data cannot be read with another tenant's key, even by a query that reaches the wrong rows.

This page describes the building blocks, and lists which built-in fields are encrypted.

## What is encrypted

| Where | Fields |
|---|---|
| Contacts | first and last name, email address, phone number, job title, LinkedIn address, time zone, and date of birth (the age is worked out in code, `Contact::age()`) |
| Companies | name, domain, phone number |
| Timeline (notes, calls, tasks, emails) | title, body and metadata of every activity |
| Property history | old and new value |
| Deals | the notes written when a deal is lost |
| Quotes | the notes and the internal notes |

Custom properties follow in a later release. Ids, stages, statuses, dates, amounts and other structure stay plain, so lists, filters, pipelines and reports keep working. An encrypted field cannot be searched or sorted with SQL: the activity timeline, for example, is no longer searchable by subject. Contacts and companies are found through a search index, described below.

Code that needs to find an activity again cannot look inside its (encrypted) metadata. It looks for the activity's plain `marker`, a short tag such as `cadence:12:3` that `ActivityMarker` builds from the metadata when the activity is saved.

## Fields that already hold data

The migrations that encrypt these fields also encrypt the rows already stored, with the key described below, so keep a copy of that key before you update. If you have changed the data between updates, or run several databases, `php artisan odden:encrypt-existing` encrypts whatever is still plain; it is safe to run again. A multi-tenant host sets `ODDEN_ENCRYPTION_CONVERT_ON_MIGRATE=false` and runs the command inside each tenant, so every tenant's rows are encrypted with that tenant's key.

## The key

On a self-hosted install, one secret in the environment gives every tenant's key:

```bash
php artisan odden:encryption-key
```

Put the result in `.env` as `ODDEN_ENCRYPTION_KEY` and keep a copy somewhere safe: data encrypted with the key cannot be read without it. If you set no key, the application key (`APP_KEY`) is used, which works but ties the data to `APP_KEY`. Changing it makes encrypted data unreadable, so set a key of its own for anything that matters.

To change the key, move the old one to `ODDEN_ENCRYPTION_PREVIOUS_KEYS` (comma-separated). Values written with the old key stay readable and new values use the new one.

A multi-tenant host replaces the key provider with its own, one key per tenant, by binding `Odden\Core\Contracts\KeyProvider`. Odden Cloud keeps each workspace's key wrapped by a master key that never leaves AWS KMS.

## Casts

```php
use Odden\Core\Encryption\Casts\EncryptedDate;
use Odden\Core\Encryption\Casts\EncryptedJson;
use Odden\Core\Encryption\Casts\EncryptedString;

protected function casts(): array
{
    return [
        'notes' => EncryptedString::class.':contacts.notes',
        'date_of_birth' => EncryptedDate::class.':contacts.date_of_birth',
        'extra' => EncryptedJson::class.':contacts.extra',
    ];
}
```

Name the field after the colon. The name is part of what is protected: a value copied into another column fails to decrypt. Keep it stable: renaming it makes stored values unreadable. The column must be `text`, because the stored form is longer than the value.

An encrypted column cannot be searched or sorted with SQL.

## Finding records: the search index

Contacts and companies must still be found by who they are, so each keeps keyed hashes of its identifying values in a side table (`odden_search_index`: the field, a hash, the record id). The hash is an HMAC made with a key derived from the tenant's key, the field and the kind of match, so the table shows only how many records share a value, and nothing can be compared across tenants or fields. It holds no names and no addresses.

| Field | What can be asked |
|---|---|
| Contact email | the whole address (ignoring case) |
| Contact phone | the whole number (ignoring punctuation) |
| Contact first and last name | equal to, a word of, or the start of a word (two to 24 letters) |
| Contact job title | equal to, or a word of |
| Company domain | the whole domain |
| Company name | equal to, a word of, or the start of a word |

Everything else is not indexed. Searching for a part from the middle of a name or an address (`nnie`, `@gmail`), or sorting, cannot use the index (see the fallback below).

A model that has `HasBlindIndexes` keeps its rows up to date when it is saved or deleted for good, and has the scopes that use them:

```php
Contact::query()->whereEmail('Dana@Example.com');          // the whole address
Contact::query()->whereNameStartsWith('ann le');           // Annie Lee
Contact::query()->searchIdentity($typedInSearchBox);        // names, or a whole address or phone number
Company::query()->whereDomain('https://www.acme.com/');
Company::query()->whereNameStartsWith('acm');
Contact::query()->whereBlindExactIn('last_name', ['Lee', 'Day']);
```

Code that writes the columns without the model (a bulk `update()`, a raw insert) skips the index. Call `SearchIndex::replace()` for those records, or run `php artisan odden:encrypt-existing`, which rewrites the index for every contact and company.

To add the index to your own model, implement `BlindIndexed`, use `HasBlindIndexes` and declare the columns in `blindIndexedColumns()`. Register the model with `EncryptedColumns::addIndexed()` so the command rebuilds it.

### When the index cannot answer

Rules of an active list on an encrypted field use the index for equals, not equals, in and not in (where the field has a whole-value index), and the database for is set and is not set. Anything else (`contains`, or equals on a field without a whole-value index) is checked in PHP: the records every other rule left are read, decrypted and tested one by one. Greater than and less than are not available on encrypted fields.

That fallback, and sorting the contacts and companies tables by name, are limited to `odden-core.encryption.fallback_limit` records (`ODDEN_ENCRYPTION_FALLBACK_LIMIT`, 10,000 by default). Above it the list sync throws `TooManyToFilter` and the table keeps its natural order and says why. Put the other rules in front of the one that needs it (a lifecycle stage, an owner), or raise the limit. A host with a plan limit on contacts rebinds `Odden\Core\Encryption\Index\FallbackLimit`.

Measured on a laptop with 50,000 contacts: a `contains` rule over a text field takes 0.3 s on SQLite, 0.7 s on MariaDB and 1.0 s on PostgreSQL, and sorting the contacts by name takes 0.7 s, 0.8 s and 1.2 s. At 10,000 contacts each is about a fifth of that. Index lookups (an email, the start of a name) take milliseconds at any size. `tests/Benchmark` measures it on your own database (`ODDEN_BENCH_CONTACTS=10000,50000`).

## Sensitive custom properties

A custom property can be marked **Sensitive** (Settings, Property definitions, "Sensitive data"). Its values are then stored encrypted inside the record's `properties` JSON, one value at a time, under the field name `properties.<entity>.<name>`, and so is every change recorded for it in the property history. Turning the switch on or off queues `ConvertSensitiveProperty`, which converts the stored values in either direction. It can be run again at any time: a value already in the right form is left alone, and a value that does not decrypt is never overwritten. `php artisan odden:convert-sensitive-properties` does the same for every property (`--check` only counts what is left).

A sensitive property belongs to a data area (Sensitive unless you choose another), so the data-area rules apply: a person without the area sees it masked, cannot save over it, and can open it for a record with a reason; the assistant never sees it.

What it costs:

- It cannot be searched. A property marked searchable loses that when it turns Sensitive, and it is left out of the global search and of Filament's table search.
- `whereProperty()` refuses it with `SensitivePropertyNotQueryable`.
- A rule on it in an active list is checked in PHP, like the other encrypted fields (up to the fallback limit above). Every operator works, `>`, `>=`, `<` and `<=` compare numbers. The list editor says so.
- A property cannot be renamed, moved to another entity or deleted while it is Sensitive. Turn the switch off first (the values are decrypted), then change it.
- Its name and label are not encrypted. Do not put a secret in them.

Measured on a laptop with SQLite, 50,000 contacts: encrypting every value of one property takes 2.1 s, and an `equals` rule on it takes 0.35 s. The benchmark (`ODDEN_BENCH_CONTACTS`, see above) measures it on your own database.

## Business data: deal amounts and sales targets

Off by default. With `ODDEN_ENCRYPT_BUSINESS_DATA=true` (`odden-core.encryption.business_data`; a host with workspaces binds `Odden\Core\Contracts\BusinessData` to a per-workspace setting) deal amounts and sales targets are stored encrypted in a companion text column (`amount_encrypted`, `target_amount_encrypted`) and the plain column is left at 0.00. A row is read correctly in either form, so the time between flipping the setting and converting the stored rows is safe. Run `php artisan odden:convert-business-data --to=encrypted` (or `--to=plain` to go back; `--check` counts what is left). It can be run again and again, converts in chunks inside transactions and leaves rows that are already in the right form alone.

The database can no longer add, average or sort what it cannot read. Everything in the application that did so goes through `Odden\Core\Encryption\EncryptedAmounts`, which adds up the plain rows in SQL, and reads and decrypts only the encrypted ones:

| Where | Behaviour with the setting on |
| --- | --- |
| Pipeline forecast, weighted forecast, averages, per-currency totals | Computed in PHP over the deals of the report. |
| Quota attainment and the executive overview | The same. |
| Kanban column totals | The same. |
| Sorting the deals table by amount | The ids are sorted in PHP and handed to the query. |
| "Largest deals" | Ordered in PHP, cut to the top few. |
| History | Records the real old and new figure, encrypted like every other history value. |

All of it is limited to `odden-core.encryption.fallback_limit` deals. Above it the page shows a message (or keeps its natural order) and does not guess; narrow the report with a filter, or raise the limit. A workspace that does not use the setting pays for one extra `exists` query per sort and nothing else.

Code that adds up or sorts the amount column with SQL (`sum('amount')`, `orderBy('amount')`) gives a wrong answer once a workspace encrypts. Use `EncryptedAmounts` (the test suite has a guard that fails on the obvious forms). Code that reads a deal with a partial `select` must include `amount_encrypted` too: a read that would otherwise report 0.00 throws instead.

Measured on a laptop with SQLite and 50,000 deals: the sum of all amounts takes 0.3 s encrypted (0.01 s plain), the whole pipeline forecast 1.2 s (0.06 s), the 25 largest deals 0.4 s (0.01 s), and converting all amounts takes 2.1 s to encrypt and 2.7 s to decrypt. `ODDEN_BENCH_DEALS=10000,50000 vendor/bin/pest tests/Benchmark` in `packages/sales` measures it on your own database.

Not covered: quotes, quote items and deal products keep their prices in plain columns, and amounts that were once copied into the audit log or action log by other code paths are not rewritten. Backups and database logs written before the switch was turned on still hold the plain figures.

## Plain values from before

While `odden-core.encryption.allow_plaintext` is on (the default), a value that is not encrypted is read as it is and is encrypted the next time the record is saved. Turn it off after every row has been converted, so a plain value where an encrypted one belongs is an error.

## What this does not do

It protects data at rest. Someone who controls the running application can read what the application can read. Values sent to an outside service on purpose (for example to an AI provider with your own key) leave the system.
