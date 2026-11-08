<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\QuoteResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Filament\Resources\QuoteResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Actions\CreateQuoteAction;

class CreateQuote extends CreateRecord
{
    protected static string $resource = QuoteResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(CreateQuoteAction::class)->execute($data, OddenAuthorization::userId());
    }
}
