<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Closure;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
use Odden\Core\Support\UserModel;
use Odden\Filament\Resources\SalesMeetingLinkResource\Pages\CreateSalesMeetingLink;
use Odden\Filament\Resources\SalesMeetingLinkResource\Pages\EditSalesMeetingLink;
use Odden\Filament\Resources\SalesMeetingLinkResource\Pages\ListSalesMeetingLinks;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Services\MeetingAvailability;
use UnitEnum;

class SalesMeetingLinkResource extends Resource
{
    protected static ?string $model = SalesMeetingLink::class;

    protected static ?string $modelLabel = 'meeting link';

    protected static ?string $pluralModelLabel = 'Meeting links';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CalendarDays;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Meeting details')
                    ->schema([
                        Select::make('user_id')
                            ->label('Assigned sales rep')
                            ->options(fn (): array => UserModel::query()->pluck('name', 'id')->all())
                            ->default(fn (): ?int => auth()->id() !== null ? (int) auth()->id() : null)
                            ->searchable()
                            ->required(),
                        TextInput::make('title')
                            ->label('Meeting title')
                            ->placeholder('e.g. 30 Min Discovery Call')
                            ->default('30 Min Meeting')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label('Booking slug (URL handle)')
                            ->prefix(url('/meet').'/')
                            ->placeholder('e.g. beth-caldwell')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(255),
                        Select::make('duration_minutes')
                            ->label('Duration')
                            ->options([
                                15 => '15 Minutes',
                                30 => '30 Minutes',
                                45 => '45 Minutes',
                                60 => '60 Minutes',
                            ])
                            ->default(30)
                            ->required(),
                        Textarea::make('description')
                            ->label('Public description for prospects')
                            ->placeholder('Schedule a brief strategic discovery session to align on requirements...')
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active link (accepting bookings)')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Availability')
                    ->description('Slots are offered inside these weekly working hours, in the timezone below. Leave every day empty to use the default hours from odden-sales.meetings.default_working_hours (Monday to Friday, 09:00-17:00 unless changed).')
                    ->schema([
                        Select::make('timezone')
                            ->label('Timezone')
                            ->options(fn (): array => array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                            ->placeholder('App timezone ('.config('app.timezone', 'UTC').')')
                            ->searchable(),
                        TextInput::make('buffer_minutes')
                            ->label('Buffer between meetings')
                            ->helperText('Minutes kept free before and after each booking.')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(240)
                            ->suffix('min')
                            ->default(0)
                            ->required(),
                        ...array_map(
                            fn (string $day): TagsInput => TagsInput::make("working_hours.{$day}")
                                ->label(ucfirst($day))
                                ->placeholder('Add window (e.g. 09:00-12:00)')
                                ->nestedRecursiveRules([
                                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                        if (! is_string($value) || MeetingAvailability::parseWindow($value) === null) {
                                            $fail('Each window must be HH:MM-HH:MM with the start before the end, for example 09:00-17:00.');
                                        }
                                    },
                                ]),
                            MeetingAvailability::DAYS,
                        ),
                    ])
                    ->columns(2),
            ]);
    }

    /**
     * Tidy the working hours from the form: trimmed windows, days without windows dropped, and
     * null when no day has any (so the link falls back to the configured default hours).
     *
     * @return array<string, list<string>>|null
     */
    public static function normalizeWorkingHours(mixed $hours): ?array
    {
        if (! is_array($hours)) {
            return null;
        }

        $normalized = [];

        foreach (MeetingAvailability::DAYS as $day) {
            $windows = $hours[$day] ?? [];

            if (! is_array($windows)) {
                continue;
            }

            $windows = array_values(array_filter(
                array_map(fn (mixed $window): string => is_string($window) ? trim($window) : '', $windows),
                fn (string $window): bool => $window !== '',
            ));

            if ($windows !== []) {
                $normalized[$day] = $windows;
            }
        }

        return $normalized === [] ? null : $normalized;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label('Meeting title')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('user.name')
                    ->label('Sales rep')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')
                    ->label('Public URL')
                    ->formatStateUsing(fn ($state): string => route('odden.meetings.show', $state, false))
                    ->color('primary')
                    ->url(fn (SalesMeetingLink $record): string => route('odden.meetings.show', ['slug' => $record->slug]), shouldOpenInNewTab: true),
                TextColumn::make('duration_minutes')
                    ->label('Duration')
                    ->formatStateUsing(fn ($state): string => $state.' min')
                    ->badge(),
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
            'index' => ListSalesMeetingLinks::route('/'),
            'create' => CreateSalesMeetingLink::route('/create'),
            'edit' => EditSalesMeetingLink::route('/{record}/edit'),
        ];
    }
}
