<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use WeakMap;

/**
 * Remembers a value computed for a record object, so a table column that needs it for its state, colour and tooltip
 * computes it once per row instead of once per use. Entries go away with the record object, so a re-rendered table
 * (which loads fresh records) computes again.
 *
 * @internal
 */
final class RecordMemo
{
    /** @var WeakMap<object, array<string, mixed>>|null */
    private static ?WeakMap $values = null;

    /**
     * @template TValue
     *
     * @param  Closure(): TValue  $compute
     * @return TValue
     */
    public static function remember(object $record, string $key, Closure $compute): mixed
    {
        $values = self::$values ??= new WeakMap;

        $entries = $values[$record] ?? [];

        if (! array_key_exists($key, $entries)) {
            $entries[$key] = $compute();
            $values[$record] = $entries;
        }

        return $entries[$key];
    }
}
