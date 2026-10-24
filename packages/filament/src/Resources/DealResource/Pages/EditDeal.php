<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\DealResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Support\CustomPropertyFieldBuilder;
use Odden\Filament\Support\RevealAreaAction;

class EditDeal extends EditRecord
{
    protected static string $resource = DealResource::class;

    /**
     * Stage, pipeline and status change only through the stage actions, which run the guards, keep the history
     * and fire the deal events. The form shows them read-only; this keeps a crafted request from saving them.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['pipeline_id'], $data['stage_id'], $data['status'], $data['closed_at']);

        return CustomPropertyFieldBuilder::mergeIntoRecord($this->getRecord(), $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            RevealAreaAction::make(),
            DeleteAction::make(),
        ];
    }
}
