<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Sales\Models\SalesMeetingLink;

class CreateMeetingLinkAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes): SalesMeetingLink
    {
        return DB::transaction(fn (): SalesMeetingLink => SalesMeetingLink::create($attributes));
    }
}
