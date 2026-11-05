<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Odden\Core\Encryption\EncryptedSearch as CoreEncryptedSearch;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;
use Odden\Sales\Support\EncryptedSalesSearch;

final class EncryptedSearch
{
    /**
     * @return Closure(Builder<Contact>, string): Builder<Contact>
     */
    public static function contacts(): Closure
    {
        return CoreEncryptedSearch::contacts();
    }

    /**
     * @return Closure(Builder<Company>, string): Builder<Company>
     */
    public static function companies(): Closure
    {
        return CoreEncryptedSearch::companies();
    }

    /**
     * @return Closure(Builder<Deal>, string): Builder<Deal>
     */
    public static function deals(): Closure
    {
        return EncryptedSalesSearch::deals();
    }

    /**
     * @return Closure(Builder<Quote>, string): Builder<Quote>
     */
    public static function quotes(): Closure
    {
        return EncryptedSalesSearch::quotes();
    }

    /**
     * @return Closure(Builder<Quote>, string): Builder<Quote>
     */
    public static function quotesOfDeals(): Closure
    {
        return EncryptedSalesSearch::quotesOfDeals();
    }

    /**
     * @param  Builder<Company>  $query
     * @return Builder<Company>
     */
    public static function companyMatches(Builder $query, string $search): Builder
    {
        return CoreEncryptedSearch::companyMatches($query, $search);
    }

    /**
     * @param  list<string>  $columns
     * @return Closure(Builder<covariant Model>, string): Builder<covariant Model>
     */
    public static function sortBy(array $columns): Closure
    {
        return CoreEncryptedSearch::sortBy($columns, static function (): void {
            Notification::make()
                ->title('Too many records to sort by this column')
                ->body('Names are encrypted, so sorting by them needs every record decrypted. Narrow the list with a filter or a search first.')
                ->warning()
                ->send();
        });
    }

    /**
     * @return Closure(Builder<covariant Model>, string): Builder<covariant Model>
     */
    public static function keepsOrder(): Closure
    {
        return CoreEncryptedSearch::keepsOrder();
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<string>  $columns
     * @return Collection<int, TModel>
     */
    public static function limitedAndSorted(Builder $query, array $columns, int $limit): Collection
    {
        return CoreEncryptedSearch::limitedAndSorted($query, $columns, $limit);
    }
}
