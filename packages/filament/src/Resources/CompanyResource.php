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
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Odden\Core\Actions\CalculateCustomerHealthScoreAction;
use Odden\Core\Actions\MergeCompaniesAction;
use Odden\Core\Actions\SummarizeTimelineAction;
use Odden\Core\Enums\CustomerHealthStatus;
use Odden\Core\Models\Company;
use Odden\Filament\Resources\CompanyResource\Pages\CreateCompany;
use Odden\Filament\Resources\CompanyResource\Pages\EditCompany;
use Odden\Filament\Resources\CompanyResource\Pages\ListCompanies;
use Odden\Filament\Resources\CompanyResource\Pages\ViewCompany;
use Odden\Filament\Resources\CompanyResource\RelationManagers\ContactsRelationManager;
use Odden\Filament\Resources\RelationManagers\ActivitiesRelationManager;
use Odden\Filament\Resources\RelationManagers\DealsRelationManager;
use Odden\Filament\Resources\RelationManagers\PropertyHistoryRelationManager;
use Odden\Filament\Support\CustomPropertyFieldBuilder;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Marketing\Actions\CalculateCompanyIntentScoreAction;
use Odden\Sales\Models\Deal;
use UnitEnum;

class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingOffice;

    protected static UnitEnum|string|null $navigationGroup = 'CRM';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company Details')
                    ->schema([
                        TextInput::make('name')
                            ->label('Company Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('domain')
                            ->label('Domain')
                            ->placeholder('acme.com')
                            ->maxLength(255),
                        TextInput::make('industry')
                            ->label('Industry')
                            ->maxLength(255),
                        Select::make('account_tier')
                            ->label('ABM Target Account Tier')
                            ->options([
                                'tier_1' => 'Tier 1 (Strategic Enterprise)',
                                'tier_2' => 'Tier 2 (Target Account)',
                                'tier_3' => 'Tier 3 (Inbound Scale)',
                            ])
                            ->placeholder('Non-Target Account'),
                        Select::make('health_status')
                            ->label('Customer Health Standing')
                            ->options([
                                'healthy' => 'Healthy (Low Churn Risk)',
                                'neutral' => 'Neutral (Stable)',
                                'at_risk' => 'At Risk (High Churn Risk)',
                            ])
                            ->default('healthy'),
                        TextInput::make('health_score')
                            ->label('Health Score (0-100)')
                            ->numeric()
                            ->default(70)
                            ->minValue(0)
                            ->maxValue(100),
                    ])
                    ->columns(2),
                ...CustomPropertyFieldBuilder::makeSection('company'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Company Name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('domain')
                    ->label('Domain')
                    ->searchable()
                    ->copyable(),
                TextColumn::make('industry')
                    ->label('Industry')
                    ->searchable()
                    ->badge(),
                TextColumn::make('account_tier')
                    ->label('ABM Tier')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'tier_1' => 'Tier 1',
                        'tier_2' => 'Tier 2',
                        'tier_3' => 'Tier 3',
                        default => 'General',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'tier_1' => 'danger',
                        'tier_2' => 'warning',
                        'tier_3' => 'info',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('intent_score')
                    ->label('Intent Score')
                    ->badge()
                    ->color(fn (int $state): string => $state >= 75 ? 'danger' : ($state >= 30 ? 'warning' : 'gray'))
                    ->formatStateUsing(fn (Company $record): string => "{$record->intent_score} pts".($record->intent_surge ? ' ⚡' : ''))
                    ->sortable(),
                TextColumn::make('health_status')
                    ->label('Health')
                    ->badge()
                    ->formatStateUsing(fn (?CustomerHealthStatus $state): string => $state?->label() ?? 'Healthy')
                    ->color(fn (?CustomerHealthStatus $state): string => $state?->color() ?? 'success')
                    ->icon(fn (?CustomerHealthStatus $state): string => $state?->badgeIcon() ?? 'heroicon-m-check-circle')
                    ->sortable(),
                TextColumn::make('health_score')
                    ->label('Health Score')
                    ->badge()
                    ->formatStateUsing(fn (int $state): string => "{$state}/100")
                    ->color(fn (int $state): string => $state >= 70 ? 'success' : ($state >= 40 ? 'warning' : 'danger'))
                    ->sortable(),
                TextColumn::make('phone')
                    ->label('Phone'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                ...CustomPropertyFieldBuilder::searchableColumns('company'),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('recalculateHealth')
                    ->label('Recalculate Health')
                    ->icon(Heroicon::Heart)
                    ->color('success')
                    ->visible(fn (): bool => class_exists(CalculateCustomerHealthScoreAction::class))
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->action(function (Company $record): void {
                        app(CalculateCustomerHealthScoreAction::class)->execute($record);

                        Notification::make()
                            ->title('Customer Health Recalculated')
                            ->body("Health score: {$record->health_score}/100 ({$record->health_status->label()})")
                            ->success()
                            ->send();
                    }),
                Action::make('recalculateIntent')
                    ->label('Recalculate Intent')
                    ->icon(Heroicon::Bolt)
                    ->color('warning')
                    ->visible(fn (): bool => class_exists(CalculateCompanyIntentScoreAction::class))
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->action(function (Company $record): void {
                        app(CalculateCompanyIntentScoreAction::class)->execute($record);

                        Notification::make()
                            ->title('ABM Intent Updated')
                            ->body("Intent score recalculated: {$record->intent_score} pts".($record->intent_surge ? ' (SURGING)' : ''))
                            ->success()
                            ->send();
                    }),
                Action::make('ai_briefing')
                    ->label('AI Briefing')
                    ->icon(Heroicon::Sparkles)
                    ->color('info')
                    ->authorize(OddenAuthorization::forRecord('view', self::class))
                    ->modalHeading(fn (Company $record): string => "Odden Breeze: {$record->name}")
                    ->modalDescription('AI timeline and relationship intelligence summary.')
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (Company $record) => view('odden-filament::components.ai-briefing-modal', [
                        'briefing' => app(SummarizeTimelineAction::class)->execute($record),
                    ])),
                Action::make('merge')
                    ->label('Merge')
                    ->icon(Heroicon::ArrowsRightLeft)
                    ->color('warning')
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->modalHeading('Merge Duplicate Company')
                    ->modalDescription('Merge another duplicate company into this record. All contacts, activities, and tickets will be reparented and preserved.')
                    ->form([
                        Select::make('secondary_company_id')
                            ->label('Select Duplicate Company to Merge into This Record')
                            ->options(fn (Company $record): array => OddenAuthorization::query(self::class, Company::class)
                                ->whereKeyNot($record->getKey())
                                ->orderBy('name')
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (Company $c): array => [$c->id => "{$c->name} ({$c->domain})"])
                                ->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Company $record, array $data): void {
                        // The duplicate is deleted by the merge, so it needs `delete` as well.
                        $secondary = OddenAuthorization::findAndAuthorize(self::class, Company::class, $data['secondary_company_id'], 'delete');
                        abort_if($secondary->is($record), 422);

                        app(MergeCompaniesAction::class)->execute($record, $secondary);

                        Notification::make()
                            ->title('Companies Merged')
                            ->body("Merged duplicate {$secondary->name} into this record.")
                            ->success()
                            ->send();
                    }),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        $relations = [
            ContactsRelationManager::class,
        ];

        if (class_exists(Deal::class)) {
            $relations[] = DealsRelationManager::class;
        }

        $relations[] = ActivitiesRelationManager::class;
        $relations[] = PropertyHistoryRelationManager::class;

        return $relations;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCompanies::route('/'),
            'create' => CreateCompany::route('/create'),
            'view' => ViewCompany::route('/{record}'),
            'edit' => EditCompany::route('/{record}/edit'),
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
