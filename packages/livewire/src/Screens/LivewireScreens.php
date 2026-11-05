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
use Odden\Livewire\Concerns\AuthorizesScreen;
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
        Route::getRoutes()->refreshNameLookups();

        $routable = [];
        $paths = [];

        foreach (Screens::all() as $screen) {
            $drawer = Screens::drawerFor($screen->key, self::SHELL);

            if ($drawer === null || Screens::isExcepted($screen->key) || ! Screens::moduleEnabled($screen->module)) {
                continue;
            }

            $class = Screens::replacements()[$drawer->class] ?? $drawer->class;
            $path = self::path($screen);

            self::guardRoutable($screen, $class);

            if (isset($paths[$path])) {
                throw new InvalidScreenRegistrationException("The screens [{$paths[$path]}] and [{$screen->key}] both map to the path [{$path}].");
            }

            $paths[$path] = $screen->key;
            $routable[] = [$screen, $class, $path];
        }

        usort($routable, fn (array $a, array $b): int => $a[0]->record <=> $b[0]->record);

        Route::prefix($prefix)
            ->middleware($middleware)
            ->name(self::ROUTE_PREFIX)
            ->group(function () use ($routable): void {
                foreach ($routable as [$screen, $class, $path]) {
                    Route::livewire($path, $class)->name($screen->key);
                }
            });
    }

    private static function guardRoutable(Screen $screen, string $class): void
    {
        if (! is_subclass_of($class, Component::class)) {
            throw new InvalidScreenRegistrationException("The screen [{$screen->key}] is drawn by [{$class}], which is not a Livewire component.");
        }

        if (! in_array(AuthorizesScreen::class, class_uses_recursive($class), true)) {
            throw new InvalidScreenRegistrationException("The screen [{$screen->key}] is drawn by [{$class}], which does not use ".AuthorizesScreen::class.' and would be served without a permission check.');
        }

        if (Route::has(self::ROUTE_PREFIX.$screen->key)) {
            throw new InvalidScreenRegistrationException('The route ['.self::ROUTE_PREFIX."{$screen->key}] for the screen [{$screen->key}] is already registered.");
        }
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
