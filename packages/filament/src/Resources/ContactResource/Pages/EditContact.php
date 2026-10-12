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
use Odden\Core\Actions\TransitionLifecycleStageAction;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Exceptions\InvalidLifecycleStageTransitionException;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Support\CustomPropertyFieldBuilder;
use Odden\Filament\Support\OddenAuthorization;

class EditContact extends EditRecord
{
    protected static string $resource = ContactResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CustomPropertyFieldBuilder::mergeIntoRecord($this->getRecord(), $data);
    }

    /**
     * A lifecycle stage change goes through TransitionLifecycleStageAction, so it is validated against the
     * lifecycle rules, recorded as a transition, stamps `became_*_at` and fires LifecycleStageChanged. A
     * refused transition is a validation error on the field, and nothing is saved.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Contact $record */
        $record->fill($data);

        if (! $record->isDirty('lifecycle_stage')) {
            $record->save();

            return $record;
        }

        $raw = $record->getAttribute('lifecycle_stage');
        $toStage = $raw instanceof LifecycleStage ? $raw : LifecycleStage::from((string) $raw);

        // The action reads the current stage from the record, then saves it with the other changes.
        $record->setAttribute('lifecycle_stage', $record->getOriginal('lifecycle_stage'));

        $userId = OddenAuthorization::userId();

        try {
            app(TransitionLifecycleStageAction::class)->execute($record, $toStage, 'manual', is_numeric($userId) ? (int) $userId : null);
        } catch (InvalidLifecycleStageTransitionException $e) {
            throw ValidationException::withMessages(['data.lifecycle_stage' => $e->getMessage()]);
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
