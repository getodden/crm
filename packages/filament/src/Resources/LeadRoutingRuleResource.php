<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Core\Support\UserModel;
use Odden\Filament\Resources\LeadRoutingRuleResource\Pages\CreateLeadRoutingRule;
use Odden\Filament\Resources\LeadRoutingRuleResource\Pages\EditLeadRoutingRule;
use Odden\Filament\Resources\LeadRoutingRuleResource\Pages\ListLeadRoutingRules;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Models\LeadRoutingRule;
use UnitEnum;

class LeadRoutingRuleResource extends Resource
{
    protected static ?string $model = LeadRoutingRule::class;

    protected static ?string $modelLabel = 'Lead Routing Rule';

    protected static ?string $pluralModelLabel = 'Lead Routing Rules';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowsRightLeft;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Routing Configuration')
                    ->schema([
                        TextInput::make('name')
                            ->label('Rule Name')
                            ->placeholder('e.g. Inbound Enterprise Distribution')
                            ->required()
                            ->maxLength(255),
                        Select::make('strategy')
                            ->label('Routing Strategy')
                            ->options(collect(LeadRoutingStrategy::cases())->mapWithKeys(
                                fn (LeadRoutingStrategy $strategy): array => [$strategy->value => $strategy->label()]
                            )->all())
                            ->required()
                            ->default(LeadRoutingStrategy::RoundRobin->value),
                        Select::make('assigned_user_ids')
                            ->label('Eligible Sales Reps')
                            ->options(fn (): array => UserModel::query()->pluck('name', 'id')->all())
                            ->multiple()
                            ->required()
                            ->searchable()
                            ->columnSpanFull(),
                        KeyValue::make('criteria')
                            ->label('Matching Criteria (Optional Filters e.g. min_amount: 10000, timezone: Europe/Berlin)')
                            ->helperText('Supported fields: lead_status, timezone, city (contacts); pipeline_id, min_amount (deals).')
                            ->keyLabel('Field')
                            ->valueLabel('Value')
                            // Refuse fields no lead can be matched on here, rather than when the rule is saved.
                            ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                // LeadRoutingRule::unsupportedCriteria() arrives with getodden/crm#123.
                                if (! is_array($value) || ! method_exists(LeadRoutingRule::class, 'unsupportedCriteria')) {
                                    return;
                                }

                                $unsupported = LeadRoutingRule::unsupportedCriteria($value);

                                if ($unsupported !== []) {
                                    $fail('Lead routing rules cannot match on: '.implode(', ', $unsupported).'.');
                                }
                            })
                            ->columnSpanFull(),
                        TextInput::make('sort_order')
                            ->label('Evaluation Priority')
                            ->numeric()
                            ->default(0)
                            ->helperText('Lower numbers are evaluated first.')
                            ->required(),
                        Toggle::make('is_active')
                            ->label('Active Rule')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')
                    ->label('Priority')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Rule Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('strategy')
                    ->label('Strategy')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof LeadRoutingStrategy ? $state->label() : (LeadRoutingStrategy::tryFrom((string) $state)?->label() ?? (string) $state)),
                // The state is the list of user ids; one summary for the row, not one per id.
                TextColumn::make('reps_pool')
                    ->label('Reps Pool')
                    ->state(fn (LeadRoutingRule $record): string => count($record->assigned_user_ids ?? []).' '.str('rep')->plural(count($record->assigned_user_ids ?? []))),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
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

    public static function getPages(): array
    {
        return [
            'index' => ListLeadRoutingRules::route('/'),
            'create' => CreateLeadRoutingRule::route('/create'),
            'edit' => EditLeadRoutingRule::route('/{record}/edit'),
        ];
    }
}
