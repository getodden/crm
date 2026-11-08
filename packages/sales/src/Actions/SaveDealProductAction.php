<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealProduct;

class SaveDealProductAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Deal $deal, array $attributes, ?DealProduct $product = null): DealProduct
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($deal);

        return DB::transaction(function () use ($deal, $attributes, $product): DealProduct {
            if ($product === null) {
                return $deal->products()->create($attributes);
            }

            $product->fill($attributes)->save();

            return $product;
        });
    }
}
