<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\PipelineResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Odden\Filament\Resources\PipelineResource;
use Odden\Sales\Models\Pipeline;

class CreatePipeline extends CreateRecord
{
    protected static string $resource = PipelineResource::class;

    protected function afterCreate(): void
    {
        /** @var Pipeline $record */
        $record = $this->getRecord();

        PipelineResource::keepSingleDefault($record);
    }
}
