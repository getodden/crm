<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Filament\Resources\SlaPolicyResource\Pages\CreateSlaPolicy;
use Odden\Filament\Resources\SlaPolicyResource\Pages\EditSlaPolicy;
use Odden\Filament\Resources\SlaPolicyResource\Pages\ListSlaPolicies;
use Odden\Service\Models\SlaPolicy;
use UnitEnum;

class SlaPolicyResource extends Resource
{
    protected static ?string $model = SlaPolicy::class;

    protected static ?string $modelLabel = 'SLA Policy';

    protected static ?string $pluralModelLabel = 'SLA Policies';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Clock;

    protected static UnitEnum|string|null $navigationGroup = 'Service';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('General SLA Policy Settings')
                    ->schema([
                        TextInput::make('name')
                            ->label('Policy Name')
                            ->placeholder('e.g. Enterprise Tier 1 SLA')
                            ->required()
                            ->maxLength(255),
                        Toggle::make('is_default')
                            ->label('Default Policy for New Tickets')
                            ->default(false),
                        Toggle::make('is_active')
                            ->label('Active Policy')
                            ->default(true),
                        Textarea::make('description')
                            ->label('Description')
                            ->placeholder('Describes when this SLA applies and commitment levels...')
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->columns(3),

                Section::make('Operating Hours & Holiday Schedule')
                    ->description('When enabled, SLA timers freeze outside business hours, on weekends, and on specified holidays.')
                    ->schema([
                        Toggle::make('only_business_hours')
                            ->label('Enforce Business Operating Hours')
                            ->default(false),
                        TextInput::make('business_hours_start')
                            ->label('Daily Start Time')
                            ->placeholder('09:00')
                            ->default('09:00')
                            ->regex('/^([01]?\d|2[0-3]):[0-5]\d$/')
                            ->required(),
                        TextInput::make('business_hours_end')
                            ->label('Daily End Time')
                            ->placeholder('17:00')
                            ->default('17:00')
                            ->regex('/^([01]?\d|2[0-3]):[0-5]\d$/')
                            ->required(),
                        Select::make('timezone')
                            ->label('Operating Timezone')
                            ->options([
                                'UTC' => 'UTC',
                                'America/New_York' => 'Eastern Time (US)',
                                'America/Chicago' => 'Central Time (US)',
                                'America/Denver' => 'Mountain Time (US)',
                                'America/Los_Angeles' => 'Pacific Time (US)',
                                'Europe/London' => 'London (GMT/BST)',
                                'Europe/Paris' => 'Central European Time',
                                'Asia/Tokyo' => 'Tokyo (JST)',
                            ])
                            ->default('UTC')
                            ->searchable(),
                        CheckboxList::make('business_days')
                            ->label('Active Business Days')
                            ->options([
                                1 => 'Monday',
                                2 => 'Tuesday',
                                3 => 'Wednesday',
                                4 => 'Thursday',
                                5 => 'Friday',
                                6 => 'Saturday',
                                7 => 'Sunday',
                            ])
                            ->default([1, 2, 3, 4, 5])
                            ->required()
                            ->minItems(1)
                            ->columns(4)
                            ->columnSpanFull(),
                        TagsInput::make('holidays')
                            ->label('Exempt Holidays (YYYY-MM-DD)')
                            ->placeholder('Add date (e.g. 2026-12-25)')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Response & Resolution Time Limits (in Minutes)')
                    ->description('Target maximum minutes allowed before first response or resolution is flagged as breached.')
                    ->schema([
                        TextInput::make('urgent_first_response_minutes')
                            ->label('Urgent: First Response (min)')
                            ->numeric()
                            ->default(60)
                            ->required(),
                        TextInput::make('urgent_resolution_minutes')
                            ->label('Urgent: Resolution (min)')
                            ->numeric()
                            ->default(240)
                            ->required(),
                        TextInput::make('high_first_response_minutes')
                            ->label('High: First Response (min)')
                            ->numeric()
                            ->default(120)
                            ->required(),
                        TextInput::make('high_resolution_minutes')
                            ->label('High: Resolution (min)')
                            ->numeric()
                            ->default(480)
                            ->required(),
                        TextInput::make('medium_first_response_minutes')
                            ->label('Medium: First Response (min)')
                            ->numeric()
                            ->default(240)
                            ->required(),
                        TextInput::make('medium_resolution_minutes')
                            ->label('Medium: Resolution (min)')
                            ->numeric()
                            ->default(1440)
                            ->required(),
                        TextInput::make('low_first_response_minutes')
                            ->label('Low: First Response (min)')
                            ->numeric()
                            ->default(480)
                            ->required(),
                        TextInput::make('low_resolution_minutes')
                            ->label('Low: Resolution (min)')
                            ->numeric()
                            ->default(2880)
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Policy Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->sortable(),
                TextColumn::make('urgent_first_response_minutes')
                    ->label('Urgent Target')
                    ->formatStateUsing(fn ($state): string => $state.' min')
                    ->badge()
                    ->color('danger'),
                TextColumn::make('high_first_response_minutes')
                    ->label('High Target')
                    ->formatStateUsing(fn ($state): string => $state.' min')
                    ->badge()
                    ->color('warning'),
                TextColumn::make('medium_first_response_minutes')
                    ->label('Medium Target')
                    ->formatStateUsing(fn ($state): string => $state.' min')
                    ->badge()
                    ->color('info'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
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

    public static function getPages(): array
    {
        return [
            'index' => ListSlaPolicies::route('/'),
            'create' => CreateSlaPolicy::route('/create'),
            'edit' => EditSlaPolicy::route('/{record}/edit'),
        ];
    }
}
