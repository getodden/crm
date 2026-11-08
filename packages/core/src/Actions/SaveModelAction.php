<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;

class SaveModelAction
{
    /**
     * @param  Model|class-string<Model>  $target
     * @param  array<string, mixed>  $attributes
     */
    public function execute(Model|string $target, array $attributes): Model
    {
        $record = $target instanceof Model ? $target : new $target;

        if ($record->exists) {
            app(TenantGuard::class)->assertOwnedWhenTenanted($record);
        }

        return DB::transaction(function () use ($record, $attributes): Model {
            $record->fill($attributes)->save();

            return $record;
        });
    }
}
