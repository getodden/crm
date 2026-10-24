<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\EncryptedAmounts;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\TooManyToFilter;

/**
 * Sorting a table by a money figure that a workspace may keep encrypted. While every figure is plain the database sorts. Once one is
 * stored encrypted the figures of the rows the table's filters leave are read, ordered in PHP and handed to the query as a list;
 * above the fallback limit the table keeps its natural order and says why.
 *
 * Use as `->sortable(query: AmountSort::query('amount'))`.
 */
final class AmountSort
{
    /**
     * @return Closure(Builder<covariant Model>, string): Builder<covariant Model>
     */
    public static function query(string $column): Closure
    {
        return static function (Builder $query, string $direction) use ($column): Builder {
            $amounts = app(EncryptedAmounts::class);
            $descending = strtolower($direction) === 'desc';

            if (! $amounts->anyEncrypted($query, $column)) {
                return $query->orderBy($query->getModel()->qualifyColumn($column), $descending ? 'desc' : 'asc');
            }

            try {
                return DecryptedFilter::orderByIds($query, $amounts->orderedIds($query, $column, $descending));
            } catch (TooManyToFilter) {
                Notification::make()
                    ->title('Too many records to sort by this column')
                    ->body('Amounts are encrypted, so sorting by them needs every amount decrypted. Narrow the list with a filter first.')
                    ->warning()
                    ->send();

                return $query;
            }
        };
    }
}
