<?php

declare(strict_types=1);

namespace Odden\Livewire;

use Illuminate\Support\ServiceProvider;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Livewire\Screens\LivewireScreens;

class LivewireServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ScreenRegistry::class, fn (ScreenRegistry $screens) => LivewireScreens::register($screens));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'odden-livewire');
    }
}
