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
     * Create through CreateCompanyAction: it normalises the domain (so "https://Acme.com/" matches "acme.com"),
     * fires CompanyCreated and enriches the company when the installation has that turned on.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateCompanyAction::class)->execute($data);
    }
}
