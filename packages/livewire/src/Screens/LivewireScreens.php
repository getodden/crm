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
use ReflectionMethod;

final class LivewireScreens
{
    public const SHELL = 'livewire';

    public const PRIORITY = 10;

    public const ROUTE_PREFIX = 'odden-livewire.';

    /**
     * @param  class-string<Component>  $component
     */
    public static function component(ScreenRegistry $screens, string $key, string $component, ?string $path = null): void
    {
        if (! is_subclass_of($component, Component::class)) {
            throw new InvalidScreenRegistrationException("[{$component}] is not a Livewire component, so it cannot draw the screen [{$key}].");
        }

        $screens->draw($key, new ScreenDrawer(self::SHELL, $component, path: $path));
    }

    public static function register(ScreenRegistry $screens): void
    {
        $screens->shell(self::SHELL, LivewireScreenShell::class, self::PRIORITY);

        self::component($screens, 'contacts.index', ListContacts::class);
    }

    /**
     * @param  list<string>  $middleware
     * @param  list<string>|null  $only
     */
    public static function routes(string $prefix = '', array $middleware = ['web', 'auth'], ?array $only = null): void
    {
        Route::getRoutes()->refreshNameLookups();

        $routable = [];
        $paths = [];

        foreach (Screens::all() as $screen) {
            $drawer = Screens::drawerFor($screen->key, self::SHELL);

            if ($drawer === null || Screens::isExcepted($screen->key) || ! Screens::moduleEnabled($screen->module)) {
                continue;
            }

            if ($only !== null && ! in_array($screen->key, $only, true)) {
                continue;
            }

            $class = Screens::replacements()[$drawer->class] ?? $drawer->class;
            $path = $drawer->path ?? self::path($screen);

            self::guardRoutable($screen, $class);
            self::guardPath($screen, $drawer->path);

            if (isset($paths[$path])) {
                throw new InvalidScreenRegistrationException("The screens [{$paths[$path]}] and [{$screen->key}] both map to the path [{$path}].");
            }

            $paths[$path] = $screen->key;
            $routable[] = [$screen, $class, $path];
        }

        self::guardOnly($only, array_map(fn (array $entry): string => $entry[0]->key, $routable));

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

    /**
     * @param  list<string>|null  $only
     * @param  list<string>  $routableKeys
     */
    private static function guardOnly(?array $only, array $routableKeys): void
    {
        foreach ($only ?? [] as $key) {
            if (! in_array($key, $routableKeys, true)) {
                throw new InvalidScreenRegistrationException("The screen [{$key}] is listed in only, but it is unknown, excepted, in a disabled module or not drawn by the Livewire shell, so it cannot get a route.");
            }
        }
    }

    private static function guardRoutable(Screen $screen, string $class): void
    {
        if (! is_subclass_of($class, Component::class)) {
            throw new InvalidScreenRegistrationException("The screen [{$screen->key}] is drawn by [{$class}], which is not a Livewire component.");
        }

        if (! in_array(AuthorizesScreen::class, class_uses_recursive($class), true)) {
            throw new InvalidScreenRegistrationException("The screen [{$screen->key}] is drawn by [{$class}], which does not use ".AuthorizesScreen::class.' and would be served without a permission check.');
        }

        $authorizedKey = (new ReflectionMethod($class, 'screenKey'))->invoke(null);

        if ($authorizedKey !== $screen->key) {
            throw new InvalidScreenRegistrationException("The screen [{$screen->key}] is drawn by [{$class}], which authorizes the screen [{$authorizedKey}] instead.");
        }

        if (Route::has(self::ROUTE_PREFIX.$screen->key)) {
            throw new InvalidScreenRegistrationException('The route ['.self::ROUTE_PREFIX."{$screen->key}] for the screen [{$screen->key}] is already registered.");
        }
    }

    private static function guardPath(Screen $screen, ?string $explicitPath): void
    {
        if ($explicitPath === null) {
            return;
        }

        $segments = explode('/', $explicitPath);
        $recordSegments = 0;

        foreach ($segments as $segment) {
            if ($segment === '{record}') {
                $recordSegments++;

                continue;
            }

            if (preg_match('/\A[a-z0-9]+(-[a-z0-9]+)*\z/', $segment) !== 1) {
                throw self::invalidPath($screen, $explicitPath);
            }
        }

        if ($recordSegments !== ($screen->record ? 1 : 0)) {
            throw self::invalidPath($screen, $explicitPath);
        }
    }

    private static function invalidPath(Screen $screen, string $explicitPath): InvalidScreenRegistrationException
    {
        $placeholder = $screen->record ? 'a record screen must contain {record} exactly once' : 'a screen that is not a record screen must not contain {record}';

        return new InvalidScreenRegistrationException("The screen [{$screen->key}] has the path [{$explicitPath}], which is not allowed: a path is one or more segments separated by a slash, each lowercase letters and digits with single hyphens between them or exactly {record}, and {$placeholder}.");
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
