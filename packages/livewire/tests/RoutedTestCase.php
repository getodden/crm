<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Odden\Livewire\Screens\LivewireScreens;

abstract class RoutedTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $routePrefix = 'crm';

    protected function defineRoutes($router): void
    {
        $router->get('/login', fn (): string => 'login')->middleware('web')->name('login');

        $this->registerLivewireRoutes($router);
    }

    protected function registerLivewireRoutes(Router $router): void
    {
        LivewireScreens::routes($this->routePrefix, ['web', 'auth']);
    }
}
