<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;

class CreateDealAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, int|string|null $userId = null): Deal
    {
        $attributes = Arr::except($attributes, ['closed_at', 'lost_reason', 'lost_notes']);
        $attributes['owner_id'] ??= $userId;
        $attributes['status'] = DealStatus::Open->value;

        return DB::transaction(function () use ($attributes, $userId): Deal {
            $deal = Deal::create($attributes);

            DealStageHistory::create([
                'deal_id' => $deal->id,
                'from_stage_id' => null,
                'to_stage_id' => $deal->stage_id,
                'user_id' => $userId,
                'entered_at' => now(),
            ]);

            return $deal;
        });
    }
}
