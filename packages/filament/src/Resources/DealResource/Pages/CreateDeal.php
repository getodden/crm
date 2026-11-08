<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\DealResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Filament\Resources\DealResource;
use Odden\Sales\Actions\CreateDealAction;

class CreateDeal extends CreateRecord
{
    protected static string $resource = DealResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateDealAction::class)->execute($data, auth()->id());
    }
}
