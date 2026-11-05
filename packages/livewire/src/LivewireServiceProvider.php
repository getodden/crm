<?php

declare(strict_types=1);

namespace Odden\Livewire;

use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Livewire\Contracts\WriteGuard;
use Odden\Livewire\Screens\LivewireScreens;
use Odden\Livewire\Search\GlobalSearch;
use Odden\Livewire\Support\AllowAllWrites;
use Odden\Livewire\Support\RefusesWrites;

class LivewireServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/odden-livewire.php', 'odden-livewire');
        $this->app->bindIf(WriteGuard::class, AllowAllWrites::class);

        $this->callAfterResolving(ScreenRegistry::class, fn (ScreenRegistry $screens) => LivewireScreens::register($screens));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'odden-livewire');

        $this->publishes([__DIR__.'/../config/odden-livewire.php' => config_path('odden-livewire.php')], 'odden-livewire-config');

        Livewire::component('odden-livewire.global-search', GlobalSearch::class);

        RefusesWrites::listen();
    }
}
