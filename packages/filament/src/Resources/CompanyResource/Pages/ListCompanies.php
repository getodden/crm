<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\CompanyResource\Pages;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Support\SortsEncryptedColumns;

class ListCompanies extends ListRecords
{
    use SortsEncryptedColumns;

    protected static string $resource = CompanyResource::class;

    protected function encryptedSortColumns(): array
    {
        return ['name' => ['name']];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
