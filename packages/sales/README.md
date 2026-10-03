# Odden Sales

`getodden/crm-sales` adds pipelines and deals, products and quotes with online acceptance, forecasting and quotas, lead routing, outbound sequences, qualification playbooks, and public meeting-booking links on top of Odden Core.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- `getodden/crm-core` (installed automatically)

## Installation

```bash
composer require getodden/crm-sales
php artisan migrate
```

The service provider is auto-discovered and loads its own migrations. Publish the config file only if you need to change something:

```bash
php artisan vendor:publish --tag=odden-sales-config
```

Some features need the scheduler (sequences, quote expiry); the installation guide lists the commands to schedule.

## Documentation

- [Sales overview](https://github.com/getodden/crm/tree/main/docs/sales/index.md)
- [Pipelines and stages](https://github.com/getodden/crm/tree/main/docs/sales/pipelines-and-stages.md), [deals](https://github.com/getodden/crm/tree/main/docs/sales/deals.md), [quotes](https://github.com/getodden/crm/tree/main/docs/sales/quotes.md)
- [Health score and forecasting](https://github.com/getodden/crm/tree/main/docs/sales/health-and-forecasting.md), [lead routing](https://github.com/getodden/crm/tree/main/docs/sales/lead-routing.md), [sequences](https://github.com/getodden/crm/tree/main/docs/sales/sequences.md), [meeting links](https://github.com/getodden/crm/tree/main/docs/sales/meeting-links.md)
- [Configuration, routes and commands](https://github.com/getodden/crm/tree/main/docs/sales/configuration.md)

The full documentation lives in the [`docs/`](https://github.com/getodden/crm/tree/main/docs/sales) folder of the [monorepo](https://github.com/getodden/crm), which is the single source of truth for behavior, signatures, configuration keys and commands. This README only covers installing the package.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE.md](LICENSE.md).
