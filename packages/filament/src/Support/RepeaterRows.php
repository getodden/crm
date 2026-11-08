<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

final class RepeaterRows
{
    private const string EXISTING_KEY_PREFIX = 'record-';

    /**
     * @param  array<array-key, array<string, mixed>>  $state
     * @return list<array<string, mixed>>
     */
    public static function withIds(array $state): array
    {
        $rows = [];

        foreach ($state as $key => $row) {
            $id = str_starts_with((string) $key, self::EXISTING_KEY_PREFIX) ? substr((string) $key, strlen(self::EXISTING_KEY_PREFIX)) : null;

            $rows[] = ['id' => $id] + $row;
        }

        return $rows;
    }
}
