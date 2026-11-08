<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\PipelineResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Filament\Resources\PipelineResource;
use Odden\Sales\Actions\SavePipelineAction;
use Odden\Sales\Models\Pipeline;

class EditPipeline extends EditRecord
{
    protected static string $resource = PipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(fn (Model $record): bool => app(DeleteModelAction::class)->execute($record))
                ->hidden(fn (Pipeline $record): bool => $record->isInUse()),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Pipeline $record */
        return app(SavePipelineAction::class)->execute($data, $record);
    }
}
