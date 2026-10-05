# Odden CRM

[![tests](https://github.com/getodden/crm/actions/workflows/tests.yml/badge.svg)](https://github.com/getodden/crm/actions/workflows/tests.yml)

**Odden** is a modular, enterprise Revenue Operations (RevOps) platform and CRM engine built on Laravel and Filament v5. Designed for extensibility and scale, Odden organizes business operations across independent packages that work together seamlessly or run as standalone headless libraries.

> **About this repository:** this is the open-source home of the Odden packages. The Laravel application at the root (`getodden/workbench`) is a development and demo harness for working on the packages; it is not the hosted Odden Cloud service at [odden.io](https://odden.io), which lives in its own private repository and installs these packages like any other consumer.

---

## Monorepo Architecture & Package Matrix

Odden is built as a set of decoupled, standalone Laravel packages residing in `packages/`:

```mermaid
graph TD
    subgraph UI ["Unified Administration"]
        F[getodden/crm-filament]
    end

    subgraph Engines ["Operational Engines"]
        S[getodden/crm-sales]
    end

    subgraph AddOns ["Paid add-ons (private)"]
        SV[Odden Service]
        M[Odden Marketing]
    end

    subgraph External ["Odden Packages"]
        MB[getodden/mail]
    end

    subgraph CoreEngine ["Headless Foundation"]
        C[getodden/crm-core]
    end

    F -.-> C
    F -.-> S
    F -.->|Modules registry| SV
    F -.->|Modules registry| M

    S --> C
    SV --> C
    M --> C
    M --> MB
    M -.->|Suggests for Closed-Loop| S
```

| Package | Namespace | Purpose | Standalone Documentation |
| :--- | :--- | :--- | :--- |
| **`getodden/crm-core`** | `Odden\Core\` | Headless CRM engine: Contacts, Companies, Custom Properties (EAV), Polymorphic Associations, Timelines, Lists, and Health Scoring. | [`packages/core/README.md`](packages/core/README.md) |
| **`getodden/crm-sales`** | `Odden\Sales\` | Revenue acceleration: Multi-pipeline Kanban, CPQ quoting, deal health scoring, stage gates, cadences, quotas, and forecasting. | [`packages/sales/README.md`](packages/sales/README.md) |
| **`getodden/mail`**| `Odden\MailBuilder\`| *Separate Odden package* ([`getodden/mail`](https://github.com/getodden/mail)), used by Odden Marketing and usable on its own. Email builder & compiler: 29 modular responsive slots, MJML/HTML reverse ingestion, WCAG 2.1 contrast audits, CID transport embedding. | [`getodden/mail`](https://github.com/getodden/mail#readme) |
| **`getodden/crm-filament`**| `Odden\Filament\` | Unified RevOps Cockpit: Single-plugin Filament v5 administration, auto-discovery of installed modules, and executive analytics. | [`packages/filament/README.md`](packages/filament/README.md) |

### Paid add-ons

**Odden Marketing** (email campaigns, drip workflows, attribution, lead scoring, landing pages) and **Odden Service** (tickets, SLAs, knowledge base, customer portal) are paid add-ons, licensed separately and delivered as private packages (see [odden.io/pricing](https://odden.io/pricing)). They build on `getodden/crm-core` and add their screens to the Filament admin through [`Odden\Filament\Support\Modules`](docs/filament/modules.md), so nothing in this repository depends on them. Releases of Marketing and Service up to version 0.9 were published under the MIT license, and that license stays with those releases.

---

## Architectural Principles

1. **Package Independence:** The operational package (`getodden/crm-sales`) and the paid add-ons can be consumed independently in any Laravel project without requiring the entire CRM.
2. **Headless First:** Business logic, state machines, and calculations reside entirely in headless Action classes and models in each package. The Filament panel acts strictly as an administrative presentation layer.
3. **Dynamic Extensibility:** Extensible EAV property engine (`PropertyDefinition`) allows defining custom fields at runtime that automatically project into Filament schemas without code modifications.
4. **Closed-Loop Attribution:** Full RevOps convergence—tracking leads from initial anonymous web session, through nurture workflows, CRM sales opportunities, quote acceptance, and post-sale support SLAs.

---

## Quick Start & Installation

### Requirements
- PHP 8.3, 8.4, or 8.5
- Composer 2.x
- Node.js & npm (for assets)
- SQLite 3.26+, MySQL 8.0+, or PostgreSQL 15+

### Setup

1. **Clone the repository and install dependencies:**
   ```bash
   git clone https://github.com/getodden/crm.git
   cd odden
   composer install
   ```

2. **Configure your environment:**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. **Run database migrations and seeders:**
   ```bash
   php artisan migrate --seed
   ```

4. **Compile frontend assets:**
   ```bash
   npm install && npm run build
   ```

5. **Start local development server:**
   ```bash
   php artisan serve
   ```
   Access the Filament RevOps Cockpit at `http://localhost:8000/admin`.

---

## Scheduled Background Jobs & Artisans

Odden relies on scheduled workers to progress sales cadences and expire quotes. The paid Marketing and Service add-ons list their own commands (campaign dispatch, workflows, SLA checks) in their documentation:

| Command | Frequency | Description |
| :--- | :--- | :--- |
| `php artisan sales:process-cadences` | Every 15 minutes | Dispatches scheduled sequence emails, phone call reminders, and tasks. |
| `php artisan sales:expire-quotes` | Daily | Expires quotes past their expiry date. |

The exact schedule, with overlap protection, is in [Installation](docs/installation.md#schedule-the-commands).

Add the standard Laravel scheduler to your server crontab:
```bash
* * * * * cd /path-to-odden && php artisan schedule:run >> /dev/null 2>&1
```

---

## Verification & Testing Suite

Odden enforces high code quality through automated test suites and strict static analysis:

```bash
# Run the complete test suite (Pest)
vendor/bin/pest --compact

# Run a specific package test suite
vendor/bin/pest packages/core/tests --compact
vendor/bin/pest packages/sales/tests --compact
vendor/bin/pest packages/filament/tests --compact

# Run PHPStan Level 8 static analysis
vendor/bin/phpstan analyse --memory-limit=2G

# Format code with Laravel Pint
vendor/bin/pint --format agent
```

---

## License

Odden is open-sourced software licensed under the [MIT license](LICENSE.md).
