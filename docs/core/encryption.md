---
title: Encrypting personal data
description: How the CRM encrypts fields that hold personal data at rest, with a key for each tenant, and how to use the casts, the key provider and the search index in your own code.
---

Fields that hold personal data can be stored encrypted, so a stolen database or backup is unreadable without the key. Each tenant has a key of its own, so one tenant's data cannot be read with another tenant's key, even by a query that reaches the wrong rows.

This page describes the building blocks, and lists which built-in fields are encrypted.

## What is encrypted

| Where | Fields |
|---|---|
| Contacts | date of birth (the age is worked out in code, `Contact::age()`) |
| Timeline (notes, calls, tasks, emails) | title, body and metadata of every activity |
| Property history | old and new value |
| Deals | the notes written when a deal is lost |
| Quotes | the notes and the internal notes |

Names, e-mail addresses, phone numbers and custom properties follow in later releases. Ids, stages, statuses, dates, amounts and other structure stay plain, so lists, filters, pipelines and reports keep working. An encrypted field cannot be searched or sorted with SQL: the activity timeline, for example, is no longer searchable by subject.

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

## Finding records by exact value

To look up a record by an encrypted value (an email address, a phone number), store a keyed hash beside it and search that:

```php
use Odden\Core\Encryption\FieldEncrypter;

$hash = app(FieldEncrypter::class)->blindIndex(strtolower($email), 'contacts.email');

Contact::where('email_index', $hash)->first();
```

Normalize the value first so equal values give equal hashes. The hash uses the tenant's key and the field's name, so the same address has a different hash in another tenant and in another field.

## Plain values from before

While `odden-core.encryption.allow_plaintext` is on (the default), a value that is not encrypted is read as it is and is encrypted the next time the record is saved. Turn it off after every row has been converted, so a plain value where an encrypted one belongs is an error.

## What this does not do

It protects data at rest. Someone who controls the running application can read what the application can read. Values sent to an outside service on purpose (for example to an AI provider with your own key) leave the system.
