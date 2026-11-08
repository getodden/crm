<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ListType;
use Odden\Core\Models\CrmList;

class CreateCrmListAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(array $attributes, ?int $creatorId = null): CrmList
    {
        return DB::transaction(function () use ($attributes, $creatorId): CrmList {
            $list = CrmList::create([...$attributes, 'created_by_id' => $creatorId]);

            $this->syncIfActive($list);

            return $list;
        });
    }

    private function syncIfActive(CrmList $list): void
    {
        if ($list->type === ListType::Active) {
            $list->syncActiveMembers();
        }
    }
}
