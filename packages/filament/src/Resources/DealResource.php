<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Core\Support\UserModel;
use Odden\Filament\Resources\DealResource\Pages\CreateDeal;
use Odden\Filament\Resources\DealResource\Pages\EditDeal;
use Odden\Filament\Resources\DealResource\Pages\KanbanDeals;
use Odden\Filament\Resources\DealResource\Pages\ListDeals;
use Odden\Filament\Resources\DealResource\Pages\ViewDeal;
use Odden\Filament\Resources\DealResource\RelationManagers\DealCompaniesRelationManager;
use Odden\Filament\Resources\DealResource\RelationManagers\DealContactsRelationManager;
use Odden\Filament\Resources\DealResource\RelationManagers\DealProductsRelationManager;
use Odden\Filament\Resources\DealResource\RelationManagers\QuotesRelationManager;
use Odden\Filament\Resources\DealResource\RelationManagers\StageHistoryRelationManager;
use Odden\Filament\Resources\RelationManagers\ActivitiesRelationManager;
use Odden\Filament\Resources\RelationManagers\PropertyHistoryRelationManager;
use Odden\Filament\Support\AmountSort;
use Odden\Filament\Support\AreaGuard;
use Odden\Filament\Support\CustomPropertyFieldBuilder;
use Odden\Filament\Support\EncryptedSearch;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Filament\Support\RecordMemo;
use Odden\Sales\Actions\CalculateDealHealthScoreAction;
use Odden\Sales\Actions\ExecuteSalesPlaybookAction;
use Odden\Sales\Actions\RouteLeadAction;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\LostReason;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\SalesPlaybook;
use Odden\Sales\Support\Money;
use UnitEnum;

class DealResource extends Resource
{
    protected static ?string $model = Deal::class;

    protected static ?string $modelLabel = 'deal';

    protected static ?string $pluralModelLabel = 'Deals';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::CurrencyDollar;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Deal Information')
                    ->schema([
                        TextInput::make('name')
                            ->label('Deal Name')
                            ->placeholder('e.g. Acme Corp - Enterprise Expansion')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        // Stage and status only change through the stage actions (Change Stage, Mark Won, Mark Lost,
                        // the board), which run the stage guards and automations, keep the stage history and fire the
                        // deal events. On edit they are shown but not saved.
                        Select::make('pipeline_id')
                            ->label('Pipeline')
                            ->relationship('pipeline', 'name')
                            ->default(fn (): ?int => Pipeline::query()->default()->value('id') ?? Pipeline::query()->value('id'))
                            ->reactive()
                            ->disabledOn('edit')
                            ->required(),
                        Select::make('stage_id')
                            ->label('Pipeline Stage')
                            ->options(function (callable $get, string $operation): array {
                                $pipelineId = $get('pipeline_id');
                                if (! $pipelineId) {
                                    return [];
                                }

                                return PipelineStage::query()
                                    ->where('pipeline_id', $pipelineId)
                                    // A new deal starts in an open stage; closing it goes through Mark Won / Mark Lost.
                                    ->when($operation === 'create', fn (Builder $query): Builder => $query
                                        ->where('is_closed_won', false)
                                        ->where('is_closed_lost', false))
                                    ->orderBy('sort_order')
                                    ->pluck('name', 'id')
                                    ->toArray();
                            })
                            ->disabledOn('edit')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? 'Use Change Stage, Mark Won or Mark Lost on the deal page to move this deal.'
                                : null)
                            ->required(),
                        TextInput::make('amount')
                            ->label('Deal Amount')
                            ->prefix(fn (callable $get): string => Money::prefix(is_string($get('currency')) ? $get('currency') : null))
                            ->numeric()
                            ->default(0.00)
                            ->required(),
                        Select::make('currency')
                            ->options(fn (?Deal $record): array => self::currencyOptions($record?->currency))
                            ->default(fn (): string => Money::defaultCurrency())
                            ->live()
                            ->required(),
                        Select::make('status')
                            ->label('Status')
                            ->options(collect(DealStatus::cases())->mapWithKeys(
                                fn (DealStatus $s) => [$s->value => $s->label()]
                            ))
                            ->default(DealStatus::Open->value)
                            ->hiddenOn('create')
                            ->disabled(),
                        DatePicker::make('expected_close_date')
                            ->label('Expected Close Date'),
                        Select::make('owner_id')
                            ->label('Deal Owner')
                            ->options(function (): array {
                                return UserModel::query()->pluck('name', 'id')->toArray();
                            })
                            ->searchable()
                            ->nullable(),
                        DateTimePicker::make('closed_at')
                            ->label('Closed Date')
                            ->disabled()
                            ->visible(fn (callable $get): bool => in_array($get('status'), [DealStatus::Won->value, DealStatus::Lost->value], true)),
                        Select::make('lost_reason')
                            ->label('Reason for Loss')
                            ->options(collect(LostReason::cases())->mapWithKeys(
                                fn (LostReason $r) => [$r->value => $r->label()]
                            ))
                            ->placeholder('Select a reason...')
                            ->visible(fn (callable $get): bool => $get('status') === DealStatus::Lost->value),
                        AreaGuard::field(Textarea::make('lost_notes'), Deal::class)
                            ->label('Loss Retrospective Notes')
                            ->placeholder('Key takeaways, competitor feedback, follow-up timeline...')
                            ->visible(fn (callable $get): bool => $get('status') === DealStatus::Lost->value)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                ...CustomPropertyFieldBuilder::makeSection('deal'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            // Days in stage, rot and health read the stage and its history for every row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['pipeline', 'stage', 'stageHistory', 'owner']))
            ->columns([
                TextColumn::make('name')
                    ->label('Deal Name')
                    ->searchable(query: EncryptedSearch::deals())
                    ->sortable(query: EncryptedSearch::sortBy(['name']))
                    ->weight('bold'),
                TextColumn::make('pipeline.name')
                    ->label('Pipeline')
                    ->badge(),
                TextColumn::make('stage.name')
                    ->label('Stage')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->money(fn (Deal $record): string => $record->currency)
                    ->sortable(query: AmountSort::query('amount'))
                    ->weight('bold'),
                TextColumn::make('days_in_stage')
                    ->label('In Stage')
                    ->getStateUsing(fn (Deal $record): string => $record->daysInCurrentStage().'d')
                    ->badge()
                    ->color(fn (Deal $record): string => $record->isRotten() ? 'danger' : 'gray')
                    ->icon(fn (Deal $record): ?Heroicon => $record->isRotten() ? Heroicon::ExclamationTriangle : null)
                    ->tooltip(fn (Deal $record): ?string => $record->isRotten() ? "Stale: Exceeded stage limit ({$record->stage->rot_after_days} days)" : null),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof DealStatus ? $state->label() : ucfirst((string) $state))
                    ->color(fn ($state): string => match ($state instanceof DealStatus ? $state : DealStatus::tryFrom((string) $state)) {
                        DealStatus::Won => 'success',
                        DealStatus::Lost => 'danger',
                        default => 'info',
                    }),
                TextColumn::make('health_score')
                    ->label('Health')
                    ->getStateUsing(fn (Deal $record, HasTable $livewire): string => self::healthScore($record, $livewire)['badge_label'])
                    ->badge()
                    ->color(fn (Deal $record, HasTable $livewire): string => self::healthScore($record, $livewire)['badge_color'])
                    ->tooltip(function (Deal $record, HasTable $livewire): string {
                        $health = self::healthScore($record, $livewire);

                        return ! empty($health['recommendations'])
                            ? $health['recommendations'][0]
                            : 'Healthy deal progression';
                    }),
                TextColumn::make('expected_close_date')
                    ->label('Target Close')
                    ->date()
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ...CustomPropertyFieldBuilder::searchableColumns('deal'),
            ])
            ->filters([
                SelectFilter::make('pipeline_id')
                    ->label('Pipeline')
                    ->relationship('pipeline', 'name'),
                SelectFilter::make('status')
                    ->options(collect(DealStatus::cases())->mapWithKeys(
                        fn (DealStatus $s) => [$s->value => $s->label()]
                    )),
                SelectFilter::make('lost_reason')
                    ->label('Loss Reason')
                    ->options(collect(LostReason::cases())->mapWithKeys(
                        fn (LostReason $r) => [$r->value => $r->label()]
                    ))
                    // The reason is stored encrypted: among the lost deals the table shows, the ones with this reason are found in PHP.
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereKey(app(DecryptedFilter::class)->ids(
                            (clone $query)->where($query->getModel()->qualifyColumn('status'), DealStatus::Lost->value),
                            ['lost_reason'],
                            fn (array $values): bool => $values['lost_reason'] === $data['value'],
                        ))
                        : $query),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('run_playbook')
                    ->label('Playbook')
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->icon(Heroicon::BookOpen)
                    ->color('primary')
                    ->form(function (): array {
                        $playbooks = SalesPlaybook::query()->where('is_active', true)->get();
                        if ($playbooks->isEmpty()) {
                            return [
                                TextInput::make('no_playbooks')
                                    ->label('Notice')
                                    ->disabled()
                                    ->default('No active playbooks found. Create one in Sales > Playbooks first.'),
                            ];
                        }

                        return [
                            Select::make('playbook_id')
                                ->label('Playbook')
                                ->options($playbooks->pluck('name', 'id'))
                                ->required()
                                ->live(),
                            Group::make()
                                ->schema(function (callable $get): array {
                                    $playbookId = $get('playbook_id');
                                    if (! $playbookId) {
                                        return [];
                                    }

                                    /** @var SalesPlaybook|null $playbook */
                                    $playbook = SalesPlaybook::find($playbookId);
                                    if (! $playbook || empty($playbook->questions)) {
                                        return [];
                                    }

                                    $fields = [];
                                    foreach ($playbook->questions as $q) {
                                        $id = 'answers.'.$q['id'];
                                        $label = $q['label'];
                                        if ($q['type'] === 'textarea') {
                                            $fields[] = Textarea::make($id)->label($label)->rows(3);
                                        } else {
                                            $fields[] = TextInput::make($id)->label($label);
                                        }
                                    }

                                    return $fields;
                                }),
                        ];
                    })
                    ->action(function (Deal $record, array $data): void {
                        if (empty($data['playbook_id'])) {
                            return;
                        }

                        /** @var SalesPlaybook $playbook */
                        $playbook = SalesPlaybook::findOrFail($data['playbook_id']);
                        $answers = isset($data['answers']) && is_array($data['answers']) ? $data['answers'] : [];

                        app(ExecuteSalesPlaybookAction::class)->execute($record, $playbook, $answers, OddenAuthorization::userId());

                        Notification::make()
                            ->title('Playbook Completed')
                            ->body("Recorded [{$playbook->name}] notes on {$record->name}.")
                            ->success()
                            ->send();
                    }),
                Action::make('route_lead')
                    ->label('Auto-Route')
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->icon(Heroicon::ArrowsRightLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Auto-Route Deal Owner')
                    ->modalDescription('Run active lead routing rules to assign this deal to a sales representative based on criteria, round-robin, or quota attainment.')
                    ->action(function (Deal $record): void {
                        $result = app(RouteLeadAction::class)->execute($record);

                        if ($result !== null) {
                            /** @var object{name: string}|null $user */
                            $user = UserModel::query()->find($result['assigned_user_id']);
                            $name = $user !== null ? $user->name : "User #{$result['assigned_user_id']}";

                            Notification::make()
                                ->title('Deal Routed')
                                ->body("Assigned to {$name} via rule [{$result['rule']->name}].")
                                ->success()
                                ->send();
                        } else {
                            Notification::make()
                                ->title('No Routing Match')
                                ->body('No active lead routing rules matched this deal.')
                                ->warning()
                                ->send();
                        }
                    }),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * The deal's health score, computed once per row (the column uses it for its label, colour and tooltip). The
     * first row to ask scores every deal on the table page in one batch, so the page costs the same few queries
     * whatever its size.
     *
     * @return array{score: int, status: string, badge_label: string, badge_color: string, factors: list<array{name: string, points: int, positive: bool, description: string}>, recommendations: list<string>}
     */
    public static function healthScore(Deal $record, ?HasTable $livewire = null): array
    {
        return RecordMemo::remember($record, 'health', function () use ($record, $livewire): array {
            $deals = [$record];

            $records = $livewire?->getTableRecords();
            $rows = match (true) {
                $records === null => [],
                $records instanceof Collection => $records->all(),
                default => $records->items(),
            };

            foreach ($rows as $row) {
                if ($row instanceof Deal && $row !== $record && ! RecordMemo::has($row, 'health')) {
                    $deals[] = $row;
                }
            }

            $scores = app(CalculateDealHealthScoreAction::class)->executeMany($deals);

            foreach ($deals as $deal) {
                if ($deal !== $record) {
                    RecordMemo::put($deal, 'health', $scores[$deal->getKey()]);
                }
            }

            return $scores[$record->getKey()];
        });
    }

    /**
     * Currencies a deal can be in: the common ones, the configured default and the deal's own (so editing a deal in
     * another currency keeps it).
     *
     * @return array<string, string>
     */
    public static function currencyOptions(?string $current = null): array
    {
        $codes = array_unique(array_filter([
            Money::defaultCurrency(),
            'USD',
            'EUR',
            'GBP',
            'CAD',
            strtoupper(trim((string) $current)),
        ]));

        $options = [];
        foreach ($codes as $code) {
            $symbol = trim(Money::prefix($code));
            $options[$code] = $symbol === $code ? $code : "{$code} ({$symbol})";
        }

        return $options;
    }

    public static function getRelations(): array
    {
        return [
            DealContactsRelationManager::class,
            DealCompaniesRelationManager::class,
            DealProductsRelationManager::class,
            QuotesRelationManager::class,
            StageHistoryRelationManager::class,
            ActivitiesRelationManager::class,
            PropertyHistoryRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeals::route('/'),
            'board' => KanbanDeals::route('/board'),
            'create' => CreateDeal::route('/create'),
            'view' => ViewDeal::route('/{record}'),
            'edit' => EditDeal::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
