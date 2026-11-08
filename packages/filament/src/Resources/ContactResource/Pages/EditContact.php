<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\ContactResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Core\Actions\UpdateContactAction;
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Support\AreaGuard;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Filament\Support\RevealAreaAction;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Contact $record */
        $record = $this->getRecord();

        if (! AreaGuard::masked(Contact::class, 'date_of_birth', $record)) {
            $data['date_of_birth'] = $record->date_of_birth?->toDateString();
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Contact $record */
        $userId = OddenAuthorization::userId();

        try {
            return app(UpdateContactAction::class)->execute($record, $data, is_numeric($userId) ? (int) $userId : null);
        } catch (InvalidLifecycleStageTransitionException $e) {
            throw ValidationException::withMessages(['data.lifecycle_stage' => $e->getMessage()]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            RevealAreaAction::make(),
            DeleteAction::make()->using(fn (Model $record): bool => app(DeleteModelAction::class)->execute($record)),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
