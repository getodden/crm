# Odden Core

`getodden/crm-core` is the headless foundation of Odden: contacts, companies and custom objects, custom properties with change history, associations between any two records, the activity timeline, lifecycle stages, lists, and duplicate merging. It has no UI and registers no routes.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13

## Installation

```bash
composer require getodden/crm-core
php artisan migrate
```

The service provider is auto-discovered and loads its own migrations. Odden tables are prefixed `odden_`.

Publish the config file only if you need to change something (table names, rate limits, the user model):

```bash
php artisan vendor:publish --tag=odden-core-config
```

## Documentation

- [Core overview](https://github.com/getodden/crm/tree/main/docs/core/index.md)
- [Contacts and companies](https://github.com/getodden/crm/tree/main/docs/core/contacts-and-companies.md), [custom properties](https://github.com/getodden/crm/tree/main/docs/core/custom-properties.md), [associations](https://github.com/getodden/crm/tree/main/docs/core/associations.md)
- [Activities and the timeline](https://github.com/getodden/crm/tree/main/docs/core/activities.md), [lifecycle stages](https://github.com/getodden/crm/tree/main/docs/core/lifecycle-stages.md), [lists](https://github.com/getodden/crm/tree/main/docs/core/lists.md), [duplicates and merging](https://github.com/getodden/crm/tree/main/docs/core/duplicates-and-merging.md)
- [Integration helpers and config keys](https://github.com/getodden/crm/tree/main/docs/core/integration.md)

The full documentation lives in the [`docs/`](https://github.com/getodden/crm/tree/main/docs/core) folder of the [monorepo](https://github.com/getodden/crm), which is the single source of truth for behavior, signatures, configuration keys and commands. This README only covers installing the package.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE.md](LICENSE.md).
