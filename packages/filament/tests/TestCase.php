<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\Actions\ActionsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Forms\FormsServiceProvider;
use Filament\Infolists\InfolistsServiceProvider;
use Filament\Notifications\NotificationsServiceProvider;
use Filament\QueryBuilder\QueryBuilderServiceProvider;
use Filament\Schemas\SchemasServiceProvider;
use Filament\Support\SupportServiceProvider;
use Filament\Tables\TablesServiceProvider;
use Filament\Widgets\WidgetsServiceProvider;
use Illuminate\Support\Facades\Gate;
use Kirschbaum\PowerJoins\PowerJoinsServiceProvider;
use Livewire\LivewireServiceProvider;
use Odden\Core\CoreServiceProvider;
use Odden\Filament\Support\Modules;
use Odden\Filament\Support\OddenPackages;
use Odden\Filament\Tests\Fixtures\AdminPanelProvider;
use Odden\Filament\Tests\Fixtures\ConfigurablePolicy;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\SalesServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider;

use function Orchestra\Testbench\after_resolving;
use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        // The registry is static, so a package left over from an earlier test must not leak into this one.
        Modules::flush();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        ConfigurablePolicy::$denied = [];
        OddenPackages::reset();
        Modules::flush();

        parent::tearDown();
    }

    /**
     * Register a policy for the given models that denies the given abilities and allows the rest.
     *
     * @param  list<class-string>  $models
     * @param  list<string>  $abilities
     */
    protected function denyAbilities(array $models, array $abilities): void
    {
        ConfigurablePolicy::$denied = $abilities;

        foreach ($models as $model) {
            Gate::policy($model, ConfigurablePolicy::class);
        }
    }

    /**
     * Boots the full Odden stack and Filament, with a fixture admin panel.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            BladeCaptureDirectiveServiceProvider::class,
            PowerJoinsServiceProvider::class,
            SupportServiceProvider::class,
            SchemasServiceProvider::class,
            ActionsServiceProvider::class,
            FormsServiceProvider::class,
            InfolistsServiceProvider::class,
            NotificationsServiceProvider::class,
            QueryBuilderServiceProvider::class,
            TablesServiceProvider::class,
            WidgetsServiceProvider::class,
            FilamentServiceProvider::class,
            CoreServiceProvider::class,
            SalesServiceProvider::class,
            \Odden\Filament\FilamentServiceProvider::class,
            AdminPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.providers.users.model', User::class);
    }

    /**
     * Laravel's own migrations (users, cache, jobs). Registered on the migrator rather than
     * run and rolled back per test: RefreshDatabase owns the schema, and rolling back
     * users fails on databases that enforce foreign keys (PostgreSQL, MySQL).
     */
    protected function defineDatabaseMigrations(): void
    {
        after_resolving($this->app, 'migrator', static function ($migrator): void {
            $migrator->path(default_migration_path());
        });
    }
}
