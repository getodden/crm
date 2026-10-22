<?php

declare(strict_types=1);

namespace Odden\Filament;

use Illuminate\Support\ServiceProvider;
use Odden\Core\Console\InstallSteps;
use Odden\Filament\Console\PanelInstallStep;

class FilamentServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'odden-filament');

        $this->callAfterResolving(InstallSteps::class, fn (InstallSteps $steps) => $steps->register(PanelInstallStep::class));
    }
}
