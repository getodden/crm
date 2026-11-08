<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\DealResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Support\RevealAreaAction;
use Odden\Sales\Actions\UpdateDealAction;
use Odden\Sales\Models\Deal;

class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Deal $record */
        return app(UpdateDealAction::class)->execute($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            RevealAreaAction::make(),
            DeleteAction::make()->using(fn (Model $record): bool => app(DeleteModelAction::class)->execute($record)),
            RestoreAction::make(),
        ];
    }
}
