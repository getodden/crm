<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Resources\RelationManagers\RelationManager;
use Odden\Core\Screens\Screens;
use Odden\Filament\OddenPlugin;

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
     * @param  list<class-string<\Filament\Resources\Resource>>  $resources
     * @param  list<class-string<Page>>  $pages
     * @param  list<class-string<RelationManager>>  $contactRelationManagers
     * @param  list<Closure(): Action>  $companyActions
     * @param  list<array{view: string, data: Closure(): array<string, mixed>, position?: 'before'|'after'}>  $executiveCards
     * @param  list<class-string<\Filament\Resources\Resource>>  $authorizationResources
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
     * @return list<string>
     */
    public static function disabledOnCurrentPanel(): array
    {
        $panel = Filament::getCurrentPanel();

        if ($panel === null || ! $panel->hasPlugin('odden')) {
            return Screens::disabledModules();
        }

        $plugin = $panel->getPlugin('odden');

        return array_values(array_unique([
            ...($plugin instanceof OddenPlugin ? $plugin->getDisabledModules() : []),
            ...Screens::disabledModules(),
        ]));
    }

    public static function enabledOnCurrentPanel(string $module): bool
    {
        return ! in_array($module, self::disabledOnCurrentPanel(), true);
    }

    public static function salesEnabled(): bool
    {
        return OddenPackages::hasSales() && self::enabledOnCurrentPanel('sales');
    }

    /**
     * @return list<class-string<RelationManager>>
     */
    public static function contactRelationManagers(): array
    {
        return self::collect('contactRelationManagers', self::disabledOnCurrentPanel());
    }

    /**
     * @return list<Action>
     */
    public static function companyActions(): array
    {
        return array_map(static fn (Closure $make): Action => $make(), self::collect('companyActions', self::disabledOnCurrentPanel()));
    }

    /**
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    public static function authorizationResources(): array
    {
        return self::collect('authorizationResources', self::disabledOnCurrentPanel());
    }

    /**
     * @return list<array{view: string, data: array<string, mixed>, position: 'before'|'after'}>
     */
    public static function executiveCards(): array
    {
        return array_map(
            static fn (array $card): array => ['view' => $card['view'], 'data' => $card['data'](), 'position' => $card['position']],
            self::collect('executiveCards', self::disabledOnCurrentPanel()),
        );
    }

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
