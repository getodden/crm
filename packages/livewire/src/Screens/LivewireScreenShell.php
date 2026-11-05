<?php

declare(strict_types=1);

namespace Odden\Livewire\Screens;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenShell;

final class LivewireScreenShell implements ScreenShell
{
    public function draws(Screen $screen, ScreenDrawer $drawer, string $class): bool
    {
        return is_subclass_of($class, Component::class)
            && Route::has(LivewireScreens::ROUTE_PREFIX.$screen->key);
    }

    public function url(Screen $screen, ScreenDrawer $drawer, string $class, Model|int|string|null $record, array $parameters, ?Model $tenant, bool $absolute): string
    {
        return route(
            LivewireScreens::ROUTE_PREFIX.$screen->key,
            $record !== null ? ['record' => $record] + $parameters : $parameters,
            $absolute,
        );
    }
}
