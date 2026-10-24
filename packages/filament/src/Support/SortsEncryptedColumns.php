<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Filament\Notifications\Notification;
use Filament\Tables\Enums\PaginationMode;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Encryption\Index\TooManyToFilter;

/**
 * For a list page whose table sorts by an encrypted column (names): the database cannot order ciphertext, so the page decrypts
 * the columns of the records the table's filters and search leave, sorts the ids in PHP and then reads one page of them.
 *
 * Sending the whole sorted list to the database in an ORDER BY costs more than the sort itself once there are tens of thousands of
 * records, so this pages the sorted ids here instead. Above the fallback limit (odden-core.encryption.fallback_limit) the table keeps
 * its natural order and says why.
 *
 * Declare the sortable columns in encryptedSortColumns() and give each column `->sortable(query: EncryptedSearch::keepsOrder())`.
 */
trait SortsEncryptedColumns
{
    /**
     * Table column name => the encrypted columns it sorts by, in turn.
     *
     * @return array<string, list<string>>
     */
    abstract protected function encryptedSortColumns(): array;

    /**
     * @param  Builder<covariant Model>  $query
     * @return Paginator<array-key, Model>|CursorPaginator<array-key, Model>
     */
    protected function paginateTableQuery(Builder $query): Paginator|CursorPaginator
    {
        $sortColumn = $this->getTableSortColumn();
        $columns = $sortColumn === null ? null : ($this->encryptedSortColumns()[$sortColumn] ?? null);

        if ($columns === null || $this->getTable()->getPaginationMode() !== PaginationMode::Default) {
            return parent::paginateTableQuery($query);
        }

        try {
            $ids = app(DecryptedFilter::class)->sortedIds($query, $columns, $this->getTableSortDirection() === 'desc');
        } catch (TooManyToFilter) {
            Notification::make()
                ->title('Too many records to sort by this column')
                ->body('Names are encrypted, so sorting by them needs every record decrypted. Narrow the list with a filter or a search first.')
                ->warning()
                ->send();

            return parent::paginateTableQuery($query);
        }

        $total = count($ids);
        $perPage = $this->getTableRecordsPerPage();
        $perPage = $perPage === 'all' ? max($total, 1) : max((int) $perPage, 1);
        $page = max((int) $this->getTablePage(), 1);

        $pageIds = array_slice($ids, ($page - 1) * $perPage, $perPage);
        $key = $query->getModel()->getQualifiedKeyName();
        $position = array_flip(array_map('strval', $pageIds));

        $records = $pageIds === []
            ? $query->getModel()->newCollection()
            : (clone $query)->reorder()->whereIn($key, $pageIds)->get()->sortBy(fn ($record): int => $position[(string) $record->getKey()])->values();

        return (new LengthAwarePaginator($records, $total, $perPage, $page, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => $this->getTablePaginationPageName(),
        ]))->onEachSide(0);
    }
}
