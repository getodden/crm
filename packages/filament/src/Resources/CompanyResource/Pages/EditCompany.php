<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\CompanyResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Core\Actions\UpdateCompanyAction;
use Odden\Core\Models\Company;
use Odden\Filament\Resources\CompanyResource;

class EditCompany extends EditRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Company $record */
        return app(UpdateCompanyAction::class)->execute($record, $data);
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->using(fn (Model $record): bool => app(DeleteModelAction::class)->execute($record)),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
