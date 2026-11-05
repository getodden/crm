<?php

declare(strict_types=1);

namespace Odden\Livewire\Screens;

use Illuminate\Support\Facades\Route;
use Livewire\Component;
use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Screens\Screens;
use Odden\Livewire\Pages\Contacts\ListContacts;

final class LivewireScreens
{
    public const SHELL = 'livewire';

    public const PRIORITY = 10;

    public const ROUTE_PREFIX = 'odden-livewire.';

    /**
     * @param  class-string<Component>  $component
     */
    public static function component(ScreenRegistry $screens, string $key, string $component): void
    {
        if (! is_subclass_of($component, Component::class)) {
            throw new InvalidScreenRegistrationException("[{$component}] is not a Livewire component, so it cannot draw the screen [{$key}].");
        }

        $screens->draw($key, new ScreenDrawer(self::SHELL, $component));
    }

    public static function register(ScreenRegistry $screens): void
    {
        $screens->shell(self::SHELL, LivewireScreenShell::class, self::PRIORITY);

        self::component($screens, 'contacts.index', ListContacts::class);
    }

    /**
     * @param  list<string>  $middleware
     */
    public static function routes(string $prefix = '', array $middleware = ['web', 'auth']): void
    {
        $routable = [];

        foreach (Screens::all() as $screen) {
            $drawer = Screens::drawerFor($screen->key, self::SHELL);

            if ($drawer === null || Screens::isExcepted($screen->key) || ! Screens::moduleEnabled($screen->module)) {
                continue;
            }

            $routable[] = [$screen, Screens::replacements()[$drawer->class] ?? $drawer->class];
        }

        usort($routable, fn (array $a, array $b): int => $a[0]->record <=> $b[0]->record);

        Route::prefix($prefix)
            ->middleware($middleware)
            ->name(self::ROUTE_PREFIX)
            ->group(function () use ($routable): void {
                foreach ($routable as [$screen, $class]) {
                    Route::livewire(self::path($screen), $class)->name($screen->key);
                }
            });
    }

    private static function path(Screen $screen): string
    {
        $position = strrpos($screen->key, '.');

        if ($position === false) {
            return $screen->record ? "{$screen->key}/{record}" : $screen->key;
        }

        $base = substr($screen->key, 0, $position);
        $page = substr($screen->key, $position + 1);

        return match (true) {
            $page === 'index' => $base,
            ! $screen->record => "{$base}/{$page}",
            $page === 'view' => "{$base}/{record}",
            default => "{$base}/{record}/{$page}",
        };
    }
}
