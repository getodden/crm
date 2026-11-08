<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\DeleteModelAction;

final class DeletesRecords
{
    public static function bulk(): Closure
    {
        return function (DeleteBulkAction $action, Collection $records): void {
            $records->each(function (Model $record) use ($action): void {
                app(DeleteModelAction::class)->execute($record) || $action->reportBulkProcessingFailure();
            });
        };
    }
}
