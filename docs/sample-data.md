---
title: Sample data
description: Load sample companies, contacts, deals and more with odden:demo to see how Odden looks, then remove it again without touching your own records.
---

A new install is empty, which makes it hard to judge. `odden:demo` fills it with a small, believable set of records so every screen has something to show, and removes it again when you are done.

```bash
php artisan odden:demo            # load the sample data
php artisan odden:demo --remove   # delete it again
```

[`odden:install`](installation.md#run-the-installer) offers it as a question. In production the command asks you to confirm; pass `--force` to skip the question.

## What it loads

Each installed module adds its own sample data, so you only get what you can use:

| Module | Sample records |
| :--- | :--- |
| Core | 5 companies, 8 contacts, 16 activities, 2 lists and 4 custom properties |
| Sales | A pipeline with 8 deals, 6 email templates, 3 sequences with 5 enrolments, quotas, 5 quotes, a playbook and a booking page |

The paid add-ons can register their own sample data the same way.

## It is safe to try

- **Nothing can reach a real person.** Every sample contact and company is on the reserved `.example` domain. The sequence runner also skips sample contacts, so no email is queued for them.
- **It never creates a user.** The first existing user owns the sample records, so the cockpits and quotas have someone's work to show. With no users, the records are simply unowned.
- **Removing it is exact.** Every sample record is listed in the `odden_demo_records` table, and `--remove` deletes only what is listed, with any activity logged on it. Your own records are never touched, even if they sit beside sample ones. The table name is configurable with `tables.demo_records` in `config/odden-core.php`.
- **Running it twice leaves one set.** A second run removes the first set before loading a new one.

The dates in the sample data are relative to the day you load it, so deals close in the future and tasks fall due soon.

## Add sample data from your own module

A package registers a seeder from its service provider. The seeder passes every record it creates through the context, which is what lets `--remove` find it:

```php
use Odden\Core\Demo\DemoContext;
use Odden\Core\Demo\DemoRegistry;
use Odden\Core\Demo\DemoSeeder;

final class SupportDemoSeeder implements DemoSeeder
{
    public function module(): string
    {
        return 'Support';
    }

    public function order(): int
    {
        return 20; // after Core (0) and Sales (10)
    }

    public function seed(DemoContext $context): void
    {
        $context->track(Ticket::query()->create([...]));
    }
}

// In the service provider's boot():
$this->callAfterResolving(DemoRegistry::class, fn (DemoRegistry $registry) => $registry->register(SupportDemoSeeder::class));
```

Use `$context->remember($group, $key, $model)` and `$context->find($group, $key)` to share records with later seeders, and `$context->ownerId()` for the owner. A module can also add its own checks to `odden:install` by registering an `Odden\Core\Console\InstallStep` with `Odden\Core\Console\InstallSteps`.
