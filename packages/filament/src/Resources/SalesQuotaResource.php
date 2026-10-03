<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Core\Support\UserModel;
use Odden\Filament\Resources\SalesQuotaResource\Pages\CreateSalesQuota;
use Odden\Filament\Resources\SalesQuotaResource\Pages\EditSalesQuota;
use Odden\Filament\Resources\SalesQuotaResource\Pages\ListSalesQuotas;
use Odden\Sales\Actions\CalculateQuotaAttainmentAction;
use Odden\Sales\Enums\QuotaPeriod;
use Odden\Sales\Models\SalesQuota;
use UnitEnum;

class SalesQuotaResource extends Resource
{
    protected static ?string $model = SalesQuota::class;

    protected static ?string $modelLabel = 'Sales Quota';

    protected static ?string $pluralModelLabel = 'Sales Quotas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Trophy;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Quota Assignment')
                    ->schema([
                        Select::make('user_id')
                            ->label('Sales Representative')
                            ->options(function (): array {
                                return UserModel::query()->pluck('name', 'id')->toArray();
                            })
                            ->searchable()
                            ->required(),
                        Select::make('pipeline_id')
                            ->label('Pipeline (Optional)')
                            ->relationship('pipeline', 'name')
                            ->placeholder('All Pipelines')
                            ->nullable(),
                        Select::make('period_type')
                            ->label('Period Type')
                            ->options(collect(QuotaPeriod::cases())->mapWithKeys(
                                fn (QuotaPeriod $p) => [$p->value => $p->label()]
                            ))
                            ->default(QuotaPeriod::Monthly->value)
                            ->required(),
                        TextInput::make('target_amount')
                            ->label('Target Revenue')
                            ->numeric()
                            ->prefix('$')
                            ->required(),
                        DatePicker::make('period_start')
                            ->label('Start Date')
                            ->required(),
                        DatePicker::make('period_end')
                            ->label('End Date')
                            ->required(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->label('Sales Rep')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('pipeline.name')
                    ->label('Pipeline')
                    ->badge()
                    ->placeholder('All Pipelines'),
                TextColumn::make('period_type')
                    ->label('Period')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst((string) $state)),
                TextColumn::make('period_start')
                    ->label('Range')
                    ->formatStateUsing(fn (SalesQuota $record): string => $record->period_start->format('M j').' - '.$record->period_end->format('M j, Y')),
                TextColumn::make('target_amount')
                    ->label('Target')
                    ->money(fn (SalesQuota $record): string => $record->currency)
                    ->weight('bold'),
                TextColumn::make('attainment')
                    ->label('Attainment')
                    ->getStateUsing(function (SalesQuota $record): string {
                        $metrics = app(CalculateQuotaAttainmentAction::class)->execute($record);

                        return "{$metrics['attainment_percent']}%";
                    })
                    ->badge()
                    ->color(function (SalesQuota $record): string {
                        $metrics = app(CalculateQuotaAttainmentAction::class)->execute($record);
                        $pct = $metrics['attainment_percent'];

                        return match (true) {
                            $pct >= 100 => 'success',
                            $pct >= 70 => 'warning',
                            default => 'danger',
                        };
                    }),
                TextColumn::make('won_closed')
                    ->label('Closed Won')
                    ->getStateUsing(function (SalesQuota $record): string {
                        $metrics = app(CalculateQuotaAttainmentAction::class)->execute($record);

                        return '$'.number_format($metrics['won_amount'], 2);
                    }),
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
            'index' => ListSalesQuotas::route('/'),
            'create' => CreateSalesQuota::route('/create'),
            'edit' => EditSalesQuota::route('/{record}/edit'),
        ];
    }
}
