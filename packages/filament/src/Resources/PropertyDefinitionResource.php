<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Models\PropertyDefinition;
use Odden\Filament\Resources\PropertyDefinitionResource\Pages\CreatePropertyDefinition;
use Odden\Filament\Resources\PropertyDefinitionResource\Pages\EditPropertyDefinition;
use Odden\Filament\Resources\PropertyDefinitionResource\Pages\ListPropertyDefinitions;
use Odden\Sales\Models\Deal;
use UnitEnum;

class PropertyDefinitionResource extends Resource
{
    protected static ?string $model = PropertyDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::AdjustmentsVertical;

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Custom Properties';

    /**
     * Entity types that render custom properties: deals only when the Sales package is installed.
     *
     * @return array<string, string>
     */
    public static function entityTypeOptions(): array
    {
        $options = ['contact' => 'Contact', 'company' => 'Company'];

        if (class_exists(Deal::class)) {
            $options['deal'] = 'Deal';
        }

        return $options;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Property Configuration')
                    ->schema([
                        Select::make('entity_type')
                            ->label('Entity Type')
                            ->options(static::entityTypeOptions())
                            ->required(),
                        TextInput::make('name')
                            ->label('Internal Key')
                            ->placeholder('e.g. annual_budget')
                            ->helperText('Lowercase machine name used in database/API.')
                            ->required()
                            ->regex('/^[a-z0-9_]+$/')
                            ->maxLength(100)
                            // The key is unique per entity type; without this a duplicate was a database error (a 500).
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('entity_type', $get('entity_type'))),
                        TextInput::make('label')
                            ->label('Display Label')
                            ->placeholder('e.g. Annual Budget')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->label('Data Type')
                            ->options(collect(PropertyType::cases())->mapWithKeys(
                                fn (PropertyType $type) => [$type->value => $type->label()]
                            ))
                            ->required()
                            ->reactive(),
                        TextInput::make('group_name')
                            ->label('Property Group')
                            ->default('general')
                            ->required(),
                        Textarea::make('description')
                            ->label('Help Text / Description')
                            ->rows(2)
                            ->columnSpanFull(),
                        KeyValue::make('options')
                            ->label('Dropdown Options (for Select / Multi-Select)')
                            ->keyLabel('Value')
                            ->valueLabel('Display Label')
                            ->visible(fn (callable $get) => in_array($get('type'), [PropertyType::Select->value, PropertyType::MultiSelect->value], true))
                            ->columnSpanFull(),
                        Toggle::make('is_required')
                            ->label('Required field'),
                        Toggle::make('is_searchable')
                            ->label('Searchable in lists'),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entity_type')
                    ->label('Entity')
                    ->badge()
                    ->sortable(),
                TextColumn::make('label')
                    ->label('Property Label')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Key')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (PropertyType|string|null $state): string => $state instanceof PropertyType ? $state->label() : ($state ?? '—')),
                TextColumn::make('group_name')
                    ->label('Group')
                    ->badge(),
                IconColumn::make('is_required')
                    ->label('Required')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('entity_type')
                    ->options(static::entityTypeOptions()),
                SelectFilter::make('type')
                    ->options(collect(PropertyType::cases())->mapWithKeys(
                        fn (PropertyType $type) => [$type->value => $type->label()]
                    )),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPropertyDefinitions::route('/'),
            'create' => CreatePropertyDefinition::route('/create'),
            'edit' => EditPropertyDefinition::route('/{record}/edit'),
        ];
    }
}
