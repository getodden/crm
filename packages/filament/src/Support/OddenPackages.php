<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Odden\Sales\Models\Deal;

/**
 * Which optional Odden packages are installed alongside Core.
 *
 * @internal
 */
final class OddenPackages
{
    /** @var array<string, bool> */
    private static array $overrides = [];

    public static function hasSales(): bool
    {
        return self::$overrides['sales'] ?? class_exists(Deal::class);
    }

    public static function hasService(): bool
    {
        return self::$overrides['service'] ?? class_exists('Odden\\Service\\Models\\Ticket');
    }

    public static function hasMarketing(): bool
    {
        return self::$overrides['marketing'] ?? class_exists('Odden\\Marketing\\Models\\Campaign');
    }

    /**
     * Pretend packages are (not) installed. For tests only.
     *
     * @param  array<'sales'|'service'|'marketing', bool>  $packages
     */
    public static function fake(array $packages): void
    {
        self::$overrides = $packages;
    }

    public static function reset(): void
    {
        self::$overrides = [];
    }
}
