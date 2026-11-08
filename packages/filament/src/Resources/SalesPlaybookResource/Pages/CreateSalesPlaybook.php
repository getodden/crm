<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\SalesPlaybookResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\SaveModelAction;
use Odden\Filament\Resources\SalesPlaybookResource;

class CreateSalesPlaybook extends CreateRecord
{
    protected static string $resource = SalesPlaybookResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveModelAction::class)->execute(static::getResource()::getModel(), $data);
    }
}
