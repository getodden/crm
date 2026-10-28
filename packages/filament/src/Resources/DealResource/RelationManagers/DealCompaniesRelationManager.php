<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\DealResource\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Odden\Core\Models\Company;
use Odden\Filament\Support\AttachesThroughAssociations;
use Odden\Filament\Support\EncryptedSearch;

class DealCompaniesRelationManager extends RelationManager
{
    protected static string $relationship = 'companies';

    protected static ?string $title = 'Associated companies';

    protected static bool $isLazy = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Company name')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (Company $record): string => (string) $record->name)
            ->columns([
                TextColumn::make('name')
                    ->label('Company')
                    ->searchable(query: EncryptedSearch::companies())
                    ->sortable(query: EncryptedSearch::sortBy(['name']))
                    ->weight('bold'),
                TextColumn::make('domain')
                    ->label('Domain')
                    ->copyable(),
                TextColumn::make('pivot.type')
                    ->label('Account relationship')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst((string) ($state ?? 'primary'))),
                TextColumn::make('industry')
                    ->label('Industry')
                    ->badge(),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query, ?string $search = null): Builder => AttachesThroughAssociations::pickerQuery($query, $search, EncryptedSearch::companies()))
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('type')
                            ->label('Account relationship')
                            ->options([
                                'primary' => 'Primary Account',
                                'subsidiary' => 'Subsidiary / Branch',
                                'partner' => 'Partner / Reseller',
                                'billing' => 'Billing Entity',
                                'other' => 'Other',
                            ])
                            ->default('primary')
                            ->required(),
                    ])
                    // Linked through AssociateRecordsAction (tenant guard, type and cardinality rules, events), not a raw pivot insert.
                    ->action(fn (AttachAction $action, array $data, Table $table) => AttachesThroughAssociations::attach($action, $this->getOwnerRecord(), $table, $data, ownerIsParent: true)),
            ])
            ->recordActions([
                DetachAction::make()->label('Unlink')->modalHeading('Unlink this record?')->modalDescription('Only the link is removed: both records stay as they are.')->modalSubmitActionLabel('Unlink'),
            ]);
    }
}
