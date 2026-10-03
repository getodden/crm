# Odden Filament

`getodden/crm-filament` is a [Filament](https://filamentphp.com) plugin that adds an admin interface for every Odden module installed in your app: resources, cockpits and dashboards for contacts and companies, sales, service and marketing.

## Requirements

- PHP 8.3 or newer
- Laravel 12 or 13
- Filament 5.9 or newer
- `getodden/crm-core` plus any of the sales, service and marketing packages

## Installation

```bash
composer require getodden/crm-filament
```

Register the plugin on your panel:

```php
use Odden\Filament\OddenPlugin;

return $panel
    // ...
    ->plugins([
        OddenPlugin::make(),
    ]);
```

The plugin registers resources and pages for the Odden packages it finds installed. To turn a module off, leave out a resource or page, or swap in your own class:

```php
OddenPlugin::make()
    ->disableModules('marketing')
    ->except(\Odden\Filament\Pages\DataQuality::class)
    ->replace(\Odden\Filament\Resources\ContactResource::class, \App\Filament\Resources\ContactResource::class);
```

## Documentation

- [Filament admin overview](https://github.com/getodden/crm/tree/main/docs/filament/index.md)
- [Resources](https://github.com/getodden/crm/tree/main/docs/filament/resources.md), [pages and cockpits](https://github.com/getodden/crm/tree/main/docs/filament/pages.md), [custom properties in forms](https://github.com/getodden/crm/tree/main/docs/filament/custom-properties.md)
- [Authorization](https://github.com/getodden/crm/tree/main/docs/filament/authorization.md), [configuration](https://github.com/getodden/crm/tree/main/docs/filament/configuration.md), [customizing and extending](https://github.com/getodden/crm/tree/main/docs/filament/customizing.md)

The full documentation lives in the [`docs/`](https://github.com/getodden/crm/tree/main/docs/filament) folder of the [monorepo](https://github.com/getodden/crm), which is the single source of truth for behavior, signatures, configuration keys and commands. This README only covers installing the package.

## Testing

```bash
composer install
vendor/bin/pest
```

## License

MIT. See [LICENSE.md](LICENSE.md).
