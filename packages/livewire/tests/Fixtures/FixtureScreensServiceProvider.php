<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Closure;
use Illuminate\Support\ServiceProvider;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenRegistry;

class FixtureScreensServiceProvider extends ServiceProvider
{
    /** @var list<string> */
    public static array $exceptions = [];

    /** @var array<class-string, class-string> */
    public static array $replacements = [];

    public static ?Closure $configure = null;

    public static function reset(): void
    {
        self::$exceptions = [];
        self::$replacements = [];
        self::$configure = null;
    }

    public static function drawInFixtureShell(string ...$keys): void
    {
        self::$configure = function (ScreenRegistry $screens) use ($keys): void {
            $screens->shell(FixtureShell::NAME, FixtureShell::class);

            foreach ($keys as $key) {
                $screens->draw($key, new ScreenDrawer(FixtureShell::NAME, PlainComponent::class));
            }
        };
    }

    public function register(): void
    {
        $this->callAfterResolving(ScreenRegistry::class, function (ScreenRegistry $screens): void {
            foreach (self::$replacements as $original => $replacement) {
                $screens->replace($original, $replacement);
            }

            $screens->except(...self::$exceptions);

            if (self::$configure !== null) {
                (self::$configure)($screens);
            }
        });
    }
}
