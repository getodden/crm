<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\CrmListResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Core\Actions\UpdateCrmListAction;
use Odden\Core\Models\CrmList;
use Odden\Filament\Resources\CrmListResource;

class EditCrmList extends EditRecord
{
    protected static string $resource = CrmListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()
                ->using(fn (Model $record): bool => app(DeleteModelAction::class)->execute($record))
                ->modalDescription('Its members stay as contacts, but campaigns and sequences that use this list lose their audience. Deleting can\'t be undone.'),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CrmList $record */
        return app(UpdateCrmListAction::class)->execute($record, $data);
    }
}
