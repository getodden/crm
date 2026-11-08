<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Sales\Models\Quote;

class CreateQuoteAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): Quote
    {
        return DB::transaction(fn (): Quote => Quote::create($attributes));
    }
}
