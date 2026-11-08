<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\PropertyDefinitionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Odden\Core\Actions\SaveModelAction;
use Odden\Core\Jobs\ConvertSensitiveProperty;
use Odden\Core\Models\PropertyDefinition;
use Odden\Filament\Resources\PropertyDefinitionResource;

class CreatePropertyDefinition extends CreateRecord
{
    protected static string $resource = PropertyDefinitionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveModelAction::class)->execute(PropertyDefinition::class, $data);
    }

    protected function afterCreate(): void
    {
        if ($this->definition()->is_sensitive) {
            ConvertSensitiveProperty::dispatch($this->definition()->getKey())->afterCommit();
        }
    }

    private function definition(): PropertyDefinition
    {
        $record = $this->getRecord();

        return $record instanceof PropertyDefinition ? $record : throw new LogicException('Expected a property definition.');
    }
}
