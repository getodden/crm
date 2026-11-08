<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\PipelineResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Filament\Resources\PipelineResource;
use Odden\Sales\Actions\SavePipelineAction;

class CreatePipeline extends CreateRecord
{
    protected static string $resource = PipelineResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SavePipelineAction::class)->execute($data);
    }
}
