<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\SalesMeetingLinkResource\Pages;

use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Filament\Resources\SalesMeetingLinkResource;
use Odden\Sales\Actions\UpdateMeetingLinkAction;
use Odden\Sales\Models\SalesMeetingLink;

class EditSalesMeetingLink extends EditRecord
{
    protected static string $resource = SalesMeetingLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            SalesMeetingLinkResource::deleteAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var SalesMeetingLink $record */
        return app(UpdateMeetingLinkAction::class)->execute($record, $data);
    }
}
