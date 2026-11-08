<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;

class DeleteModelAction
{
    public function execute(Model $record): bool
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($record);

        return DB::transaction(fn (): bool => (bool) $record->delete());
    }
}
