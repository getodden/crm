<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests;

use BladeUI\Icons\BladeIconsServiceProvider;
use Illuminate\Support\Facades\Gate;
use Livewire\LivewireServiceProvider;
use Odden\Core\CoreServiceProvider;
use Odden\Livewire\LivewireServiceProvider as OddenLivewireServiceProvider;
use Odden\Livewire\Tests\Fixtures\ConfigurablePolicy;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Ui\UiServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use OwenVoke\BladeFontAwesome\BladeFontAwesomeServiceProvider;

use function Orchestra\Testbench\after_resolving;
use function Orchestra\Testbench\default_migration_path;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        FixtureScreensServiceProvider::reset();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        ConfigurablePolicy::$denied = [];
        FixtureScreensServiceProvider::reset();

        parent::tearDown();
    }

    /**
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
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            BladeIconsServiceProvider::class,
            BladeFontAwesomeServiceProvider::class,
            UiServiceProvider::class,
            CoreServiceProvider::class,
            OddenLivewireServiceProvider::class,
            FixtureScreensServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
        $app['config']->set('auth.providers.users.model', User::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        after_resolving($this->app, 'migrator', static function ($migrator): void {
            $migrator->path(default_migration_path());
        });
    }
}
