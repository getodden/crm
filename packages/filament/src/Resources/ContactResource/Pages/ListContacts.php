<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\ContactResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;
use Odden\Core\Models\Contact;
use Odden\Filament\Imports\ContactImporter;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Filament\Support\SortsEncryptedColumns;

class ListContacts extends ListRecords
{
    use SortsEncryptedColumns;

    protected static string $resource = ContactResource::class;

    protected function encryptedSortColumns(): array
    {
        return ['full_name' => ['last_name', 'first_name']];
    }

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(ContactImporter::class)
                // Imports skip per-record policy checks, so gate the whole action on the create ability.
                ->authorize(fn (): bool => OddenAuthorization::allows('create', Contact::class, ContactResource::class)),
            CreateAction::make(),
        ];
    }
}
