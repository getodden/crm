<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\CrmListResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Odden\Core\Enums\ListType;
use Odden\Core\Models\CrmList;
use Odden\Filament\Resources\CrmListResource;

class EditCrmList extends EditRecord
{
    protected static string $resource = CrmListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->modalDescription('Its members stay as contacts, but campaigns and sequences that use this list lose their audience. Deleting can\'t be undone.'),
        ];
    }

    protected function afterSave(): void
    {
        /** @var CrmList $record */
        $record = $this->record;

        if ($record->type === ListType::Active) {
            $record->syncActiveMembers();
        }
    }
}
