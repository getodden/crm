<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\DealResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Widgets\DealPipelineForecastWidget;

class ListDeals extends ListRecords
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('board')
                ->label('Pipeline board')
                ->icon(Heroicon::ViewColumns)
                ->color('gray')
                ->url(DealResource::getUrl('board')),
            CreateAction::make()
                ->label('New deal'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DealPipelineForecastWidget::class,
        ];
    }
}
