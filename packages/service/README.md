# Odden Service

`getodden/crm-service` is Odden's help desk module: support tickets with threaded conversations, SLA policies with business hours, ticket routing, canned responses, ticket merging, a knowledge base, a customer portal, an embeddable chat widget, and an email-to-ticket webhook. It is headless; use the Filament admin or build your own agent screens.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- `getodden/crm-core` (installed automatically)

## Installation

```bash
composer require getodden/crm-service
php artisan migrate
```

The service provider is auto-discovered and loads its own migrations. Publish the config file only if you need to change something:

```bash
php artisan vendor:publish --tag=odden-service-config
```

Optionally publish the chat widget script into your public directory (it is also served at `/api/service/widget.js`):

```bash
php artisan vendor:publish --tag=odden-service-widget
```

Schedule the SLA and automation commands listed in the installation guide.

## Documentation

- [Service overview](https://github.com/getodden/crm/tree/main/docs/service/index.md)
- [Tickets](https://github.com/getodden/crm/tree/main/docs/service/tickets.md), [SLA policies](https://github.com/getodden/crm/tree/main/docs/service/sla-policies.md), [routing, canned responses and merging](https://github.com/getodden/crm/tree/main/docs/service/routing.md)
- [Knowledge base](https://github.com/getodden/crm/tree/main/docs/service/knowledge-base.md), [customer portal](https://github.com/getodden/crm/tree/main/docs/service/customer-portal.md), [chat widget](https://github.com/getodden/crm/tree/main/docs/service/chat-widget.md), [email to ticket](https://github.com/getodden/crm/tree/main/docs/service/inbound-email.md)
- [Configuration, routes and commands](https://github.com/getodden/crm/tree/main/docs/service/configuration.md)

The full documentation lives in the [`docs/`](https://github.com/getodden/crm/tree/main/docs/service) folder of the [monorepo](https://github.com/getodden/crm), which is the single source of truth for behavior, signatures, configuration keys and commands. This README only covers installing the package.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE.md](LICENSE.md).
