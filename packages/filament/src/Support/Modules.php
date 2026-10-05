<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;

/**
 * Where separately installed Odden packages plug their screens into the panel.
 *
 * A package (Odden Marketing, Odden Service, ...) registers its resources, pages and the pieces it adds to shared screens
 * here, from its own service provider's register() method, so they are known before any panel is built. The plugin and the
 * shared screens read from this registry and know nothing about the packages that fill it.
 *
 * Only class names and closures are stored, so registering costs nothing when the panel is never used.
 */
final class Modules
{
    /**
     * @var array<string, array{
     *     resources: list<class-string<\Filament\Resources\Resource>>,
     *     pages: list<class-string<Page>>,
     *     contactRelationManagers: list<class-string<RelationManager>>,
     *     companyActions: list<Closure(): Action>,
     *     executiveCards: list<array{view: string, data: Closure(): array<string, mixed>, position: 'before'|'after'}>,
     *     authorizationResources: list<class-string<\Filament\Resources\Resource>>
     * }>
     */
    private static array $modules = [];

    /**
     * Add a module, replacing anything registered under the same name (so booting the application twice does not
     * duplicate its screens).
     *
     * @param  list<class-string<\Filament\Resources\Resource>>  $resources  Registered on the panel unless the module is disabled.
     * @param  list<class-string<Page>>  $pages  Registered on the panel unless the module is disabled.
     * @param  list<class-string<RelationManager>>  $contactRelationManagers  Added to the contact screen.
     * @param  list<Closure(): Action>  $companyActions  Row actions added to the company table.
     * @param  list<array{view: string, data: Closure(): array<string, mixed>, position?: 'before'|'after'}>  $executiveCards  Summary cards for the Executive Overview, shown before or after the Sales card.
     * @param  list<class-string<\Filament\Resources\Resource>>  $authorizationResources  Resources whose permissions decide who sees the Executive Overview.
     */
    public static function register(
        string $module,
        array $resources = [],
        array $pages = [],
        array $contactRelationManagers = [],
        array $companyActions = [],
        array $executiveCards = [],
        array $authorizationResources = [],
    ): void {
        self::$modules[$module] = [
            'resources' => $resources,
            'pages' => $pages,
            'contactRelationManagers' => $contactRelationManagers,
            'companyActions' => $companyActions,
            'executiveCards' => array_map(
                static fn (array $card): array => ['view' => $card['view'], 'data' => $card['data'], 'position' => $card['position'] ?? 'after'],
                $executiveCards,
            ),
            'authorizationResources' => $authorizationResources,
        ];
    }

    public static function has(string $module): bool
    {
        return isset(self::$modules[$module]);
    }

    /**
     * @param  list<string>  $disabled
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    public static function resources(array $disabled = []): array
    {
        return self::collect('resources', $disabled);
    }

    /**
     * @param  list<string>  $disabled
     * @return list<class-string<Page>>
     */
    public static function pages(array $disabled = []): array
    {
        return self::collect('pages', $disabled);
    }

    /**
     * @return list<class-string<RelationManager>>
     */
    public static function contactRelationManagers(): array
    {
        return self::collect('contactRelationManagers');
    }

    /**
     * @return list<Action>
     */
    public static function companyActions(): array
    {
        return array_map(static fn (Closure $make): Action => $make(), self::collect('companyActions'));
    }

    /**
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    public static function authorizationResources(): array
    {
        return self::collect('authorizationResources');
    }

    /**
     * @return list<array{view: string, data: array<string, mixed>, position: 'before'|'after'}>
     */
    public static function executiveCards(): array
    {
        return array_map(
            static fn (array $card): array => ['view' => $card['view'], 'data' => $card['data'](), 'position' => $card['position']],
            self::collect('executiveCards'),
        );
    }

    /**
     * Forget everything registered. For tests only.
     */
    public static function flush(): void
    {
        self::$modules = [];
    }

    /**
     * @param  'resources'|'pages'|'contactRelationManagers'|'companyActions'|'executiveCards'|'authorizationResources'  $key
     * @param  list<string>  $disabled
     * @return list<mixed>
     */
    private static function collect(string $key, array $disabled = []): array
    {
        $items = [];

        foreach (self::$modules as $name => $module) {
            if (in_array($name, $disabled, true)) {
                continue;
            }

            array_push($items, ...$module[$key]);
        }

        return $items;
    }
}
