<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\SalesEmailTemplateResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\SaveModelAction;
use Odden\Filament\Resources\SalesEmailTemplateResource;

class CreateSalesEmailTemplate extends CreateRecord
{
    protected static string $resource = SalesEmailTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveModelAction::class)->execute(static::getResource()::getModel(), $data);
    }
}
