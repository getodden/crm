<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Models\Quote;

class UpdateQuoteAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Quote $quote, array $attributes): Quote
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($quote);

        return DB::transaction(function () use ($quote, $attributes): Quote {
            $quote->fill($attributes)->save();

            return $quote;
        });
    }
}
