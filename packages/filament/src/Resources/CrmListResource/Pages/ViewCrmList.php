<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\CrmListResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Odden\Core\Enums\ListType;
use Odden\Core\Models\CrmList;
use Odden\Filament\Resources\CrmListResource;
use Odden\Filament\Support\OddenAuthorization;

class ViewCrmList extends ViewRecord
{
    protected static string $resource = CrmListResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('sync')
                ->label('Sync members')
                ->icon(Heroicon::ArrowPath)
                ->color('primary')
                ->visible(fn (): bool => $this->getRecord() instanceof CrmList && $this->getRecord()->type === ListType::Active)
                ->authorize(OddenAuthorization::forRecord('update', CrmListResource::class))
                ->action(function (): void {
                    /** @var CrmList $record */
                    $record = $this->getRecord();
                    $synced = $record->syncActiveMembers();

                    Notification::make()
                        ->title('List synchronized')
                        ->body("Criteria evaluated and {$synced} members updated.")
                        ->success()
                        ->send();
                }),
            EditAction::make(),
        ];
    }
}
