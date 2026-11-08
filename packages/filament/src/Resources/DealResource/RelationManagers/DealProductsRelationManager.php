<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\DealResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Core\Encryption\Index\DecryptedFilter;
use Odden\Sales\Actions\SaveDealProductAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealProduct;
use Odden\Sales\Support\Money;

class DealProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Products & line items';

    protected static bool $isLazy = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Product / service name')
                ->placeholder('e.g. Enterprise License (Annual)')
                ->required()
                ->maxLength(255)
                ->columnSpan(2),
            TextInput::make('sku')
                ->label('SKU')
                ->placeholder('e.g. PROD-ENT-001')
                ->maxLength(100),
            TextInput::make('unit_price')
                ->label('Unit price')
                ->numeric()
                ->prefix(fn (): string => Money::prefix($this->getOwnerRecord()->getAttribute('currency')))
                ->required(),
            TextInput::make('quantity')
                ->label('Quantity')
                ->numeric()
                ->default(1)
                ->minValue(0.01)
                ->required(),
            TextInput::make('discount_percent')
                ->label('Discount (%)')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->maxValue(100)
                ->suffix('%'),
            Textarea::make('description')
                ->label('Description / notes')
                ->placeholder('Optional line item notes...')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Product')
                    ->weight('bold')
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereKey(
                        app(DecryptedFilter::class)->ids(clone $query, ['name'], fn (array $values): bool => mb_stripos((string) $values['name'], $search) !== false),
                    )),
                TextColumn::make('sku')
                    ->label('SKU')
                    ->badge()
                    ->placeholder('-'),
                TextColumn::make('unit_price')
                    ->label('Unit price')
                    ->money(fn (): string => $this->getOwnerRecord()->getAttribute('currency') ?? Money::defaultCurrency()),
                TextColumn::make('quantity')
                    ->label('Qty')
                    ->numeric(),
                TextColumn::make('discount_percent')
                    ->label('Discount')
                    ->suffix('%')
                    ->placeholder('0%'),
                TextColumn::make('total_price')
                    ->label('Total')
                    ->weight('bold')
                    ->money(fn (): string => $this->getOwnerRecord()->getAttribute('currency') ?? Money::defaultCurrency()),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add product')
                    ->using(fn (array $data): Model => app(SaveDealProductAction::class)->execute($this->ownerDeal(), $data)),
            ])
            ->recordActions([
                EditAction::make()
                    ->using(fn (DealProduct $record, array $data): Model => app(SaveDealProductAction::class)->execute($this->ownerDeal(), $data, $record)),
                DeleteAction::make()
                    ->using(fn (Model $record): bool => app(DeleteModelAction::class)->execute($record)),
            ]);
    }

    private function ownerDeal(): Deal
    {
        $deal = $this->getOwnerRecord();

        return $deal instanceof Deal ? $deal : throw new LogicException('Expected a deal.');
    }
}
