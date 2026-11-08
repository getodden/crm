<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Enums\ListType;
use Odden\Core\Models\CrmList;
use Odden\Core\Support\TenantGuard;

class UpdateCrmListAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function execute(CrmList $list, array $attributes): CrmList
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($list);

        return DB::transaction(function () use ($list, $attributes): CrmList {
            $list->fill($attributes)->save();

            if ($list->type === ListType::Active) {
                $list->syncActiveMembers();
            }

            return $list;
        });
    }
}
