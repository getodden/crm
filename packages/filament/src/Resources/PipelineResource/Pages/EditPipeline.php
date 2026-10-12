<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\PipelineResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Odden\Filament\Resources\PipelineResource;
use Odden\Sales\Models\Pipeline;

class EditPipeline extends EditRecord
{
    protected static string $resource = PipelineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // A pipeline with deals or stage history can't be deleted (the model and database refuse it).
            DeleteAction::make()
                ->hidden(fn (Pipeline $record): bool => $record->isInUse()),
        ];
    }
}
