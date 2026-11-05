<?php

declare(strict_types=1);

namespace Odden\Filament\Tests\Fixtures;

use Illuminate\Support\ServiceProvider;
use Odden\Core\Screens\ScreenRegistry;

class GlobalScreensServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> */
    public static array $replacements = [];

    /** @var list<string> */
    public static array $exceptions = [];

    /** @var list<string> */
    public static array $disabledModules = [];

    public static function reset(): void
    {
        self::$replacements = [];
        self::$exceptions = [];
        self::$disabledModules = [];
    }

    public function register(): void
    {
        $this->callAfterResolving(ScreenRegistry::class, function (ScreenRegistry $screens): void {
            foreach (self::$replacements as $original => $replacement) {
                $screens->replace($original, $replacement);
            }

            $screens->except(...self::$exceptions);
            $screens->disableModules(...self::$disabledModules);
        });
    }
}
