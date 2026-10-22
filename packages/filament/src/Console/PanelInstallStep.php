<?php

declare(strict_types=1);

namespace Odden\Filament\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Odden\Core\Console\InstallStep;
use Odden\Core\Support\UserModel;

/**
 * The part of `odden:install` that belongs to the admin panel: the plugin has to be registered on a panel, and someone has to be able to sign in.
 */
final class PanelInstallStep implements InstallStep
{
    public function run(Command $command): void
    {
        $directory = app_path('Providers/Filament');
        $providers = File::isDirectory($directory) ? File::allFiles($directory) : [];

        $registered = collect($providers)->contains(fn ($file): bool => str_contains(File::get($file->getPathname()), 'OddenPlugin'));

        if (! $registered) {
            $command->warn('OddenPlugin is not registered on a Filament panel. Add ->plugins([\\Odden\\Filament\\OddenPlugin::make()]) to your panel provider.');
        }

        if (! UserModel::className()::query()->exists()) {
            $command->warn('There is no user to sign in with yet. Create one with: php artisan make:filament-user');
        }
    }
}
