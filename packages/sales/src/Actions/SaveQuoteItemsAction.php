<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Core\Actions\SyncChildRecordsAction;
use Odden\Sales\Models\Quote;

class SaveQuoteItemsAction
{
    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<int|string>|null  $loadedIds
     */
    public function execute(Quote $quote, array $items, ?array $loadedIds = null): Quote
    {
        app(SyncChildRecordsAction::class)->execute($quote->items(), $items, 'sort_order', $loadedIds);

        return $quote;
    }
}
