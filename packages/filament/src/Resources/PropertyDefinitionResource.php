<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rules\Unique;
use Odden\Core\Areas\DataArea;
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

    protected static ?string $navigationLabel = 'Custom properties';

    protected static ?string $modelLabel = 'custom property';

    protected static ?string $pluralModelLabel = 'Custom properties';

    protected static bool $hasTitleCaseModelLabel = false;

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
                Section::make('Property configuration')
                    ->schema([
                        Select::make('entity_type')
                            ->label('Entity type')
                            ->options(static::entityTypeOptions())
                            ->disabled(fn (?PropertyDefinition $record): bool => (bool) $record?->is_sensitive)
                            ->required(),
                        TextInput::make('name')
                            ->disabled(fn (?PropertyDefinition $record): bool => (bool) $record?->is_sensitive)
                            ->label('Internal key')
                            ->placeholder('e.g. annual_budget')
                            ->helperText('Lowercase machine name used in database/API.')
                            ->required()
                            ->regex('/^[a-z0-9_]+$/')
                            ->maxLength(100)
                            // The key is unique per entity type; without this a duplicate was a database error (a 500).
                            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get): Unique => $rule->where('entity_type', $get('entity_type'))),
                        TextInput::make('label')
                            ->label('Display label')
                            ->placeholder('e.g. Annual Budget')
                            ->required()
                            ->maxLength(255),
                        Select::make('type')
                            ->label('Data type')
                            ->options(collect(PropertyType::cases())->mapWithKeys(
                                fn (PropertyType $type) => [$type->value => $type->label()]
                            ))
                            ->required()
                            ->reactive(),
                        TextInput::make('group_name')
                            ->label('Property group')
                            ->default('general')
                            ->required(),
                        Textarea::make('description')
                            ->label('Help text / description')
                            ->rows(2)
                            ->columnSpanFull(),
                        KeyValue::make('options')
                            ->label('Choices (for a choice or several choices)')
                            ->keyLabel('Value')
                            ->valueLabel('Display Label')
                            ->visible(fn (callable $get) => in_array($get('type'), [PropertyType::Select->value, PropertyType::MultiSelect->value], true))
                            ->columnSpanFull(),
                        Toggle::make('is_required')
                            ->label('Required field'),
                        Toggle::make('is_searchable')
                            ->label('Searchable in lists')
                            ->disabled(fn (Get $get): bool => (bool) $get('is_sensitive'))
                            ->dehydrateStateUsing(fn (mixed $state, Get $get): bool => ! $get('is_sensitive') && (bool) $state)
                            ->helperText(fn (Get $get): ?string => $get('is_sensitive') ? 'Not available for a sensitive property: its values are encrypted, so the table cannot search them.' : null),
                    ])
                    ->columns(2),

                Section::make('Sensitive data')
                    ->description('For values that must not be readable in the database, in a backup or by people who have no reason to see them: a tax number, a policy number, a medical note.')
                    ->schema([
                        Toggle::make('is_sensitive')
                            ->label('Sensitive')
                            ->live()
                            ->afterStateUpdated(function (bool $state, Set $set): void {
                                if ($state) {
                                    $set('is_searchable', false);
                                }
                            })
                            ->helperText('Its values are stored encrypted, shown only to people who hold the data area below (others see a mask, and can ask to view it with a reason), and never sent to an AI provider when the area is Sensitive. Values already stored are converted in the background; turning it off converts them back. A sensitive property cannot be renamed or deleted: turn it off first.')
                            ->columnSpanFull(),
                        Select::make('data_area')
                            ->label('Data area')
                            ->options(collect(DataArea::cases())->mapWithKeys(fn (DataArea $area): array => [$area->value => $area->label().': '.$area->description()]))
                            ->default(DataArea::Sensitive->value)
                            ->selectablePlaceholder(false)
                            ->visible(fn (Get $get): bool => (bool) $get('is_sensitive'))
                            ->required(fn (Get $get): bool => (bool) $get('is_sensitive'))
                            ->helperText('Who can see the values. Sensitive is held by owners only until they give it to others; choose the area of the work the property belongs to (for example Sales) to let people in that area see it.')
                            ->columnSpanFull(),
                        Placeholder::make('sensitive_limits')
                            ->label('What still works')
                            ->visible(fn (Get $get): bool => (bool) $get('is_sensitive'))
                            ->content('Showing and editing it on the record, and rules in a list (equals, contains, is set, and greater/less than on numbers): these are checked one record at a time, so they work up to your plan\'s limit and are refused above it. Not available: searching or sorting a table by it, and importing it (the importer has no custom property columns).')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('entity_type')
                    ->label('On')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? '' : (static::entityTypeOptions()[$state] ?? $state))
                    ->sortable(),
                TextColumn::make('label')
                    ->label('Property label')
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
                IconColumn::make('is_sensitive')
                    ->label('Sensitive')
                    ->boolean()
                    ->trueIcon(Heroicon::LockClosed)
                    ->falseIcon('')
                    ->tooltip(fn (PropertyDefinition $record): ?string => $record->is_sensitive ? 'Encrypted; data area: '.$record->area()->label() : null),
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
                DeleteAction::make()
                    ->hidden(fn (PropertyDefinition $record): bool => $record->is_sensitive),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    // A sensitive property is kept until it is turned off (PropertyDefinition refuses): its values are encrypted for a reason.
                    DeleteBulkAction::make()
                        ->before(function (Collection $records, DeleteBulkAction $action): void {
                            if ($records->contains(fn (PropertyDefinition $record): bool => $record->is_sensitive)) {
                                Notification::make()->title('A sensitive property cannot be deleted')->body('Turn Sensitive off first; its values are converted back.')->danger()->send();
                                $action->cancel();
                            }
                        }),
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
