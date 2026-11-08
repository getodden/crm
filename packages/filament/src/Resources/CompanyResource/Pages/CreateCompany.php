<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\CompanyResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\CreateCompanyAction;
use Odden\Filament\Resources\CompanyResource;

class CreateCompany extends CreateRecord
{
    protected static string $resource = CompanyResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateCompanyAction::class)->execute($data);
    }
}
