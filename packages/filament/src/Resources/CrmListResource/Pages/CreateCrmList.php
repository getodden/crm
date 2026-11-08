<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\CrmListResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\CreateCrmListAction;
use Odden\Filament\Resources\CrmListResource;

class CreateCrmList extends CreateRecord
{
    protected static string $resource = CrmListResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateCrmListAction::class)->execute($data, auth()->id());
    }
}
