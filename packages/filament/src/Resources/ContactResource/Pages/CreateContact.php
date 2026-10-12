<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\ContactResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\CreateContactAction;
use Odden\Filament\Resources\ContactResource;

class CreateContact extends CreateRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * Create through CreateContactAction: it normalises the email, runs the DNS check, fires ContactCreated and
     * auto-associates the company when the installation has that turned on.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateContactAction::class)->execute($data);
    }
}
