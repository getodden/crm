<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\QuoteResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Filament\Resources\QuoteResource;
use Odden\Sales\Actions\UpdateQuoteAction;
use Odden\Sales\Models\Quote;

class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Quote $record */
        $data['user_id'] = $record->getAttribute('user_id');

        return app(UpdateQuoteAction::class)->execute($record, QuoteResource::fillFromDeal($data));
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make()->using(fn (Model $record): bool => app(DeleteModelAction::class)->execute($record)),
            RestoreAction::make(),
        ];
    }
}
