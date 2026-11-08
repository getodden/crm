<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\SalesMeetingLinkResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Filament\Resources\SalesMeetingLinkResource;
use Odden\Sales\Actions\CreateMeetingLinkAction;

class CreateSalesMeetingLink extends CreateRecord
{
    protected static string $resource = SalesMeetingLinkResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $data['working_hours'] = SalesMeetingLinkResource::normalizeWorkingHours($data['working_hours'] ?? null);

        return app(CreateMeetingLinkAction::class)->execute($data);
    }
}
