---
title: Introduction
description: What Odden is, how its packages fit together, and where to start.
---

Odden is an open-source CRM for Laravel, delivered as Composer packages. Instead of running a separate CRM and syncing data into it, you install the modules you need into your own application: they add models, actions, events, and routes, and they store everything in your database.

## The packages

| Package | What it adds |
| :--- | :--- |
| [`getodden/crm-core`](core/index.md) | Contacts, companies, custom properties, associations, activities, lists, and the shared plumbing every module uses |
| [`getodden/crm-sales`](sales/index.md) | Pipelines, deals, products, quotes, sequences, forecasting, and booking links |
| [`getodden/crm-filament`](filament/index.md) | A Filament admin for every module you have installed |

Every module requires Core, and Composer installs it for you.

**Odden Marketing** (campaigns, forms, landing pages, lead scoring, workflows, attribution) and **Odden Service** (tickets, SLAs, a knowledge base, a customer portal) are paid add-ons, licensed separately and not part of these open packages. They build on Core, plug into the Filament admin, and you can buy either or both: see [odden.io/pricing](https://odden.io/pricing).

## Two ways to use Odden

**Headless.** Use the models and actions from your own code and build whatever interface fits your product. Each module's actions are plain classes you resolve from the container, so they work the same in a controller, a job, or a console command.

**With an admin.** Add the [Filament plugin](filament/index.md) to a Filament panel and you get resources, dashboards, and relation managers for each installed module, without building screens yourself.

You can mix the two: use the admin for your team and the actions for your product's own flows.

## Where to start

1. [Install the packages](installation.md) and run the migrations.
2. Read [Configuration](configuration.md) for the settings shared by every module: the user model, public routes, API tokens, and rate limits.
3. Learn the [Core concepts](core/index.md) that the other modules build on.

## Versioning

Odden is pre-1.0. All `getodden/*` packages are released together with the same version number, so require the same version of each. Until 1.0, a minor release (for example 0.2 to 0.3) may include breaking changes; patch releases won't. Each release is listed on [GitHub](https://github.com/getodden/crm/releases).

## Getting help

Report bugs and ask questions in the [issue tracker](https://github.com/getodden/crm/issues). Odden is MIT licensed.
