<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use Filament\Actions\DeleteBulkAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\DeleteModelAction;
use Throwable;

final class DeletesRecords
{
    public static function bulk(): Closure
    {
        return function (DeleteBulkAction $action, Collection $records): void {
            $records->each(function (Model $record) use ($action): void {
                try {
                    app(DeleteModelAction::class)->execute($record) || $action->reportBulkProcessingFailure();
                } catch (Throwable $exception) {
                    report($exception);
                    $action->reportBulkProcessingFailure();
                }
            });
        };
    }
}
