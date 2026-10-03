<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema as DbSchema;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Models\PropertyDefinition;

class CustomPropertyFieldBuilder
{
    /**
     * Build dynamic form components for a given entity type.
     *
     * @return list<Component>
     */
    public static function makeSection(string $entityType): array
    {
        // Guard against running before migrations have executed
        if (! DbSchema::hasTable(config('odden-core.tables.properties', 'odden_properties'))) {
            return [];
        }

        $definitions = PropertyDefinition::forEntity($entityType)->get();

        if ($definitions->isEmpty()) {
            return [];
        }

        $fields = [];

        foreach ($definitions as $definition) {
            $field = match ($definition->type) {
                PropertyType::Text => TextInput::make("properties.{$definition->name}"),
                PropertyType::Number => TextInput::make("properties.{$definition->name}")->numeric(),
                PropertyType::Boolean => Toggle::make("properties.{$definition->name}"),
                PropertyType::Select => Select::make("properties.{$definition->name}")
                    ->options($definition->options ?? []),
                PropertyType::MultiSelect => Select::make("properties.{$definition->name}")
                    ->multiple()
                    ->options($definition->options ?? []),
                PropertyType::Date => DatePicker::make("properties.{$definition->name}"),
                PropertyType::DateTime => DateTimePicker::make("properties.{$definition->name}"),
                PropertyType::Json => KeyValue::make("properties.{$definition->name}"),
            };

            $field->label($definition->label);

            if ($definition->is_required) {
                $field->required();
            }

            if ($definition->description) {
                $field->helperText($definition->description);
            }

            $fields[] = $field;
        }

        return [
            Section::make('Custom Properties')
                ->description('Dynamic custom fields configured for this entity.')
                ->schema($fields)
                ->collapsible(),
        ];
    }

    /**
     * Table columns for the definitions flagged "Searchable in lists". Each is searched with a
     * LIKE on its key inside the record's `properties` JSON column, and can be hidden from the
     * column picker.
     *
     * @return list<TextColumn>
     */
    public static function searchableColumns(string $entityType): array
    {
        if (! DbSchema::hasTable(config('odden-core.tables.properties', 'odden_properties'))) {
            return [];
        }

        return PropertyDefinition::forEntity($entityType)
            ->where('is_searchable', true)
            ->get()
            ->map(fn (PropertyDefinition $definition): TextColumn => TextColumn::make("properties.{$definition->name}")
                ->label($definition->label)
                ->toggleable()
                ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(
                    "properties->{$definition->name}",
                    'like',
                    '%'.$search.'%'
                )))
            ->all();
    }
}
