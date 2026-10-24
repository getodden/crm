<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Odden\Core\Areas\DataArea;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\TooManyToFilter;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

/**
 * Searching and sorting the tables and pickers of contacts and companies, whose names, addresses and phone numbers are encrypted.
 *
 * The database cannot search or sort ciphertext, so a search box matches the start of a name (and a full address or phone number)
 * through the search index, and sorting by name decrypts the records the table shows and sorts them in PHP, up to the fallback limit.
 */
final class EncryptedSearch
{
    /**
     * For `->searchable(query: ...)` on a contact column.
     *
     * @return Closure(Builder<Contact>, string): Builder<Contact>
     */
    public static function contacts(): Closure
    {
        // Searching reaches into the Identity area: without it a search finds nothing, so it cannot be used to read what is masked.
        return static fn (Builder $query, string $search): Builder => AreaGuard::lacks(DataArea::Identity)
            ? $query->whereRaw('1 = 0')
            : (new Contact)->scopeSearchIdentity($query, $search);
    }

    /**
     * For `->searchable(query: ...)` on a company column: the start of a name, or a domain.
     *
     * @return Closure(Builder<Company>, string): Builder<Company>
     */
    public static function companies(): Closure
    {
        return static fn (Builder $query, string $search): Builder => AreaGuard::lacks(DataArea::Identity)
            ? $query->whereRaw('1 = 0')
            : self::companyMatches($query, $search);
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public static function companyMatches(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->whereNameStartsWith($search)
            ->orWhere(fn (Builder $domain): Builder => $domain->whereDomain($search)));
    }

    /**
     * For `->sortable(query: ...)`: order by decrypted columns, in turn. Above the fallback limit the table keeps its order and says why.
     *
     * @param  list<string>  $columns
     * @return Closure(Builder<covariant Model>, string): Builder<covariant Model>
     */
    public static function sortBy(array $columns): Closure
    {
        return static function (Builder $query, string $direction) use ($columns): Builder {
            try {
                $ids = app(DecryptedFilter::class)->sortedIds($query, $columns, $direction === 'desc');
            } catch (TooManyToFilter $e) {
                Notification::make()
                    ->title('Too many records to sort by this column')
                    ->body('Names are encrypted, so sorting by them needs every record decrypted. Narrow the list with a filter or a search first.')
                    ->warning()
                    ->send();

                return $query;
            }

            return DecryptedFilter::orderByIds($query, $ids);
        };
    }

    /**
     * For `->sortable(query: ...)` on a column of a list page that uses SortsEncryptedColumns, which does the ordering itself when
     * it pages the records.
     *
     * @return Closure(Builder<covariant Model>, string): Builder<covariant Model>
     */
    public static function keepsOrder(): Closure
    {
        return static fn (Builder $query): Builder => $query;
    }

    /**
     * Records for a picker, ordered by decrypted columns. Only the matches the query found are decrypted, so the limit is applied
     * first and the (few) results are ordered here.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Collection<int, TModel>
     */
    public static function limitedAndSorted(Builder $query, array $columns, int $limit): Collection
    {
        $records = $query->orderBy($query->getModel()->getQualifiedKeyName())->limit($limit)->get();

        return $records->sortBy(
            fn (Model $record): string => implode("\0", array_map(fn (string $column): string => mb_strtolower((string) $record->getAttribute($column)), $columns)),
        )->values();
    }
}
