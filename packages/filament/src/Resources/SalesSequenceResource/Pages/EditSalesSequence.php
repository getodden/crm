<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\SalesSequenceResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Odden\Filament\Resources\SalesSequenceResource;

class EditSalesSequence extends EditRecord
{
    protected static string $resource = SalesSequenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->modalDescription('Contacts in it stop where they are, and nothing more is sent from it. Deleting can\'t be undone.'),
        ];
    }
}
