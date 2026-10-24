<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\PropertyDefinitionResource\Pages;

use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use LogicException;
use Odden\Core\Encryption\SensitivePropertyConverter;
use Odden\Core\Jobs\ConvertSensitiveProperty;
use Odden\Core\Models\PropertyDefinition;
use Odden\Filament\Resources\PropertyDefinitionResource;

class EditPropertyDefinition extends EditRecord
{
    protected static string $resource = PropertyDefinitionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->hidden(fn (): bool => (bool) $this->definition()->is_sensitive),
        ];
    }

    /**
     * Turning Sensitive on or off changes how every stored value of the property is kept: convert them in the background. The job
     * reads the definition as it is when it runs, so switching on and off again quickly still ends where the definition says.
     */
    protected function afterSave(): void
    {
        if ($this->definition()->wasChanged('is_sensitive')) {
            ConvertSensitiveProperty::dispatch($this->definition()->getKey())->afterCommit();

            Notification::make()
                ->title($this->definition()->is_sensitive ? 'Encrypting the stored values' : 'Decrypting the stored values')
                ->body('This runs in the background. Until it finishes, values are still shown correctly.')
                ->success()
                ->send();
        }
    }

    public function getSubheading(): ?string
    {
        $record = $this->definition();
        $pending = app(SensitivePropertyConverter::class)->pending($record);

        return $pending > 0 ? "{$pending} stored values are still being converted to match this setting." : null;
    }

    private function definition(): PropertyDefinition
    {
        $record = $this->getRecord();

        return $record instanceof PropertyDefinition ? $record : throw new LogicException('Expected a property definition.');
    }
}
