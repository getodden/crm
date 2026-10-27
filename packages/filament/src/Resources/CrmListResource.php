<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Odden\Core\Encryption\Casts\EncryptedCast;
use Odden\Core\Encryption\Index\FallbackLimit;
use Odden\Core\Encryption\Index\TooManyToFilter;
use Odden\Core\Encryption\SensitiveProperties;
use Odden\Core\Enums\ListType;
use Odden\Core\Exceptions\InvalidListCriteriaException;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Filament\Resources\CrmListResource\Pages\CreateCrmList;
use Odden\Filament\Resources\CrmListResource\Pages\EditCrmList;
use Odden\Filament\Resources\CrmListResource\Pages\ListCrmLists;
use Odden\Filament\Resources\CrmListResource\Pages\ViewCrmList;
use Odden\Filament\Resources\CrmListResource\RelationManagers\MembersRelationManager;
use Odden\Filament\Support\OddenAuthorization;
use UnitEnum;

class CrmListResource extends Resource
{
    protected static ?string $model = CrmList::class;

    protected static ?string $modelLabel = 'list';

    protected static ?string $pluralModelLabel = 'Lists';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::QueueList;

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('List Information')
                    ->schema([
                        TextInput::make('name')
                            ->label('List name')
                            ->placeholder('e.g. High Value Leads')
                            ->required()
                            ->maxLength(255),
                        Select::make('entity_type')
                            ->label('Target entity')
                            ->options([
                                (new Contact)->getMorphClass() => 'Contacts',
                                (new Company)->getMorphClass() => 'Companies',
                            ])
                            ->required(),
                        Select::make('type')
                            ->label('List type')
                            ->options(collect(ListType::cases())->mapWithKeys(
                                fn (ListType $type) => [$type->value => $type->label()]
                            ))
                            ->default(ListType::Active->value)
                            ->reactive()
                            ->required(),
                        Textarea::make('description')
                            ->label('Description')
                            ->placeholder('Brief description of purpose or targeting...')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Segmentation Rules / Criteria')
                    ->description('For Active smart lists, records matching these rules are dynamically synced.')
                    ->visible(fn (callable $get): bool => $get('type') === ListType::Active->value)
                    ->schema([
                        Repeater::make('criteria')
                            ->label('Rules')
                            ->schema([
                                TextInput::make('field')
                                    ->label('Property / field')
                                    ->placeholder('e.g. lifecycle_stage, email, lead_score')
                                    ->live(onBlur: true)
                                    ->hint(fn (Get $get): ?string => self::ruleHint((string) $get('field'), (string) $get('../../entity_type')))
                                    ->required(),
                                Select::make('operator')
                                    ->label('Condition')
                                    ->options([
                                        'equals' => 'Equals',
                                        'not_equals' => 'Does not equal',
                                        'contains' => 'Contains',
                                        'greater_than' => 'Greater than',
                                        'less_than' => 'Less than',
                                        'is_set' => 'Is set / Not empty',
                                        'is_not_set' => 'Is not set / Empty',
                                    ])
                                    ->default('equals')
                                    ->required(),
                                TextInput::make('value')
                                    ->label('Value')
                                    ->placeholder('Comparison value'),
                            ])
                            ->columns(3)
                            ->addActionLabel('Add Rule')
                            ->defaultItems(1)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    /**
     * What to tell the person about a rule on an encrypted field or a sensitive custom property: the database cannot compare it,
     * so the rule is checked record by record, and refused above the limit.
     */
    private static function ruleHint(string $field, string $entityType): ?string
    {
        $field = trim($field);

        if ($field === '') {
            return null;
        }

        if (app(SensitiveProperties::class)->isSensitive($entityType, $field)) {
            return 'Sensitive property: checked record by record (up to '.number_format(app(FallbackLimit::class)->records()).' records).';
        }

        $model = $entityType === (new Company)->getMorphClass() ? new Company : new Contact;
        $cast = $model->getCasts()[$field] ?? null;

        if (is_string($cast) && is_subclass_of(explode(':', $cast, 2)[0], EncryptedCast::class)) {
            return 'Encrypted field: equals and starts-with use an index; other conditions are checked record by record (up to '.number_format(app(FallbackLimit::class)->records()).' records); greater/less than is not available.';
        }

        return null;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('List name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('entity_type')
                    ->label('Target')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => class_basename((string) $state)),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof ListType ? $state->label() : ucfirst((string) $state))
                    ->color(fn ($state): string => match ($state instanceof ListType ? $state : ListType::tryFrom((string) $state)) {
                        ListType::Active => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('memberships_count')
                    ->counts('memberships')
                    ->label('Members')
                    ->badge()
                    ->color('info'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(ListType::cases())->mapWithKeys(
                        fn (ListType $type) => [$type->value => $type->label()]
                    )),
            ])
            ->recordActions([
                Action::make('sync')
                    ->label('Sync')
                    ->icon(Heroicon::ArrowPath)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription('The list is rebuilt from its rules now: contacts who no longer match are taken off it, and new matches are added.')
                    ->visible(fn (CrmList $record): bool => $record->type === ListType::Active)
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->action(function (CrmList $record): void {
                        try {
                            $count = $record->syncActiveMembers();
                        } catch (InvalidListCriteriaException|TooManyToFilter $e) {
                            // A rule on an encrypted field is checked record by record, up to a limit: say so instead of failing.
                            Notification::make()->title('This list cannot be synced')->body($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title('List synchronized')
                            ->body("{$count} members synced.")
                            ->success()
                            ->send();
                    }),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make()->modalDescription('Its members stay as contacts, but campaigns and sequences that use this list lose their audience. Deleting can\'t be undone.'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->modalDescription('Their members stay as contacts, but campaigns and sequences that use these lists lose their audience. Deleting can\'t be undone.'),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCrmLists::route('/'),
            'create' => CreateCrmList::route('/create'),
            'view' => ViewCrmList::route('/{record}'),
            'edit' => EditCrmList::route('/{record}/edit'),
        ];
    }
}
