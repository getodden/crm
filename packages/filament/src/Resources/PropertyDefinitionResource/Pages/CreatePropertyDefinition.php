<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\PropertyDefinitionResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use LogicException;
use Odden\Core\Jobs\ConvertSensitiveProperty;
use Odden\Core\Models\PropertyDefinition;
use Odden\Filament\Resources\PropertyDefinitionResource;

class CreatePropertyDefinition extends CreateRecord
{
    protected static string $resource = PropertyDefinitionResource::class;

    /**
     * A property created as sensitive may already have values (a key written by an import or an integration): encrypt them.
     */
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
