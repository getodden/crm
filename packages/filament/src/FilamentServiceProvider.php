<?php

declare(strict_types=1);

namespace Odden\Filament;

use Illuminate\Support\ServiceProvider;
use Odden\Core\Console\InstallSteps;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Filament\Console\PanelInstallStep;
use Odden\Filament\Screens\FilamentScreens;

class FilamentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->callAfterResolving(ScreenRegistry::class, fn (ScreenRegistry $screens) => FilamentScreens::register($screens));
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'odden-filament');

        $this->callAfterResolving(InstallSteps::class, fn (InstallSteps $steps) => $steps->register(PanelInstallStep::class));
    }
}
