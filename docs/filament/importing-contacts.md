---
title: Importing contacts
description: Import contacts from a CSV file in the admin panel, how each address is checked, and the setup the import needs.
---

The Contacts list has an **Import** button. You upload a CSV file, map its columns to contact fields, and Odden imports the rows in the background. Rows that could not be imported are kept in a file you can download, with the reason on each row.

## What can be imported

| Field | Notes |
| :--- | :--- |
| Email | Required. Lowercased and trimmed. |
| First name, last name, job title, phone | Optional |
| LinkedIn URL | Must be a URL |
| Timezone | A timezone identifier such as `America/New_York` |
| Lifecycle stage, lead status | The stored value (`in_progress`) or the label (`In Progress`) |

Column headings are matched to fields automatically where they look familiar (`E-mail`, `Surname`); you can correct the mapping before importing. The import's sample file shows the expected shape.

## How addresses are checked

Each row goes through [email validation](../core/email-validation.md) before it is saved:

| Result | What happens to the row |
| :--- | :--- |
| Valid, or unknown (the lookup could not be completed) | Imported with that status |
| Risky: typo in a well-known domain, disposable provider, shared mailbox such as `info@` | Imported, and flagged with the reason so sending can hold it back |
| Invalid: malformed, or a domain that accepts no mail | Not imported; listed in the failed rows with the reason |

Nothing is dropped silently: every row is either imported or listed with a reason.

## Existing contacts

When a contact with the same email already exists you choose, per import:

- **Skip it and list the row as failed** (default). The existing contact is untouched.
- **Update it with the values in this file.** Only the columns you mapped, and only where the file has a value, are changed.

A repeated address within the same file counts as an existing contact from its second row on.

## Setup

The import uses Filament's import feature, which needs its tables, a queue worker, and database notifications. If your application has not set this up:

```bash
php artisan vendor:publish --tag=filament-actions-migrations
php artisan make:queue-batches-table
php artisan make:notifications-table
php artisan migrate
```

Run a queue worker (`php artisan queue:work`), or set `QUEUE_CONNECTION=sync` to import inside the request, which is only suitable for small files.

## Permissions

The Import button is shown to users who may create contacts. An import does not check policies row by row, so the create ability is what gates it. Failed-rows files repeat the uploaded values, and cells starting with `=`, `+`, `-` or `@` are neutralised so a spreadsheet will not run them as formulas.

## Large files

The DNS part of validation is cached per domain, so a big list costs roughly one lookup per distinct domain, not per row. If lookups are slow in your environment, set `ODDEN_EMAIL_CHECK_DNS=false`: rows are then imported with the offline checks only and an `unknown` status until you run `ValidateContactEmailAction` on them.
