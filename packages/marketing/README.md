# Odden Marketing

`getodden/crm-marketing` adds email campaigns, workflows, forms and landing pages, lead scoring, attribution, web tracking and deliverability tools to Odden. Emails are built with [`getodden/mail`](https://github.com/getodden/mail).

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- `getodden/crm-core` and `getodden/mail` (installed automatically)
- A queue worker, because campaign mail is queued

## Installation

```bash
composer require getodden/crm-marketing
php artisan migrate
```

The service provider is auto-discovered and loads its own migrations. Publish the config file only if you need to change something:

```bash
php artisan vendor:publish --tag=odden-marketing-config
```

Schedule the marketing commands (campaign dispatch, workflows, A/B evaluation, score decay, sunset) listed in the installation guide.

## Documentation

- [Marketing overview](https://github.com/getodden/crm/tree/main/docs/marketing/index.md)
- [Campaigns](https://github.com/getodden/crm/tree/main/docs/marketing/campaigns.md), [email templates and merge tags](https://github.com/getodden/crm/tree/main/docs/marketing/email-templates.md), [workflows](https://github.com/getodden/crm/tree/main/docs/marketing/workflows.md)
- [Forms and landing pages](https://github.com/getodden/crm/tree/main/docs/marketing/forms-and-landing-pages.md), [web tracking](https://github.com/getodden/crm/tree/main/docs/marketing/web-tracking.md), [lead scoring](https://github.com/getodden/crm/tree/main/docs/marketing/lead-scoring.md), [attribution](https://github.com/getodden/crm/tree/main/docs/marketing/attribution.md)
- [Subscriptions and compliance](https://github.com/getodden/crm/tree/main/docs/marketing/subscriptions-and-compliance.md), [deliverability](https://github.com/getodden/crm/tree/main/docs/marketing/deliverability.md), [inbound webhooks](https://github.com/getodden/crm/tree/main/docs/marketing/inbound-webhooks.md)

The full documentation lives in the [`docs/`](https://github.com/getodden/crm/tree/main/docs/marketing) folder of the [monorepo](https://github.com/getodden/crm), which is the single source of truth for behavior, signatures, configuration keys and commands. This README only covers installing the package.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE.md](LICENSE.md).
