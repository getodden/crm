<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\SalesEmailTemplateResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Core\Actions\SaveModelAction;
use Odden\Filament\Resources\SalesEmailTemplateResource;

class EditSalesEmailTemplate extends EditRecord
{
    protected static string $resource = SalesEmailTemplateResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        return app(SaveModelAction::class)->execute($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->using(fn (Model $record): bool => app(DeleteModelAction::class)->execute($record)),
        ];
    }
}
