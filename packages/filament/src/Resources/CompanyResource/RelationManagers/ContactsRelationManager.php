<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\CompanyResource\RelationManagers;

use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Odden\Core\Enums\LifecycleStage;
use Odden\Filament\Support\AttachesThroughAssociations;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $recordTitleAttribute = 'email';

    protected static bool $isLazy = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')
                ->label('Email Address')
                ->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('full_name')
                    ->label('Contact Name')
                    ->searchable(['first_name', 'last_name', 'email']),
                TextColumn::make('email')
                    ->label('Email')
                    ->copyable(),
                TextColumn::make('pivot.type')
                    ->label('Role / Type')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst((string) ($state ?? 'default'))),
                TextColumn::make('lifecycle_stage')
                    ->label('Stage')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof LifecycleStage ? $state->label() : ((string) ($state ?? '—'))),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query): Builder => AttachesThroughAssociations::pickerQuery($query))
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('type')
                            ->label('Association Type')
                            ->options([
                                'primary' => 'Primary Contact',
                                'billing' => 'Billing / Finance',
                                'decision_maker' => 'Decision Maker',
                                'technical' => 'Technical Contact',
                                'influencer' => 'Influencer',
                                'other' => 'Other',
                            ])
                            ->default('primary')
                            ->required(),
                    ])
                    // Linked through AssociateRecordsAction (tenant guard, type and cardinality rules, events), not a raw pivot insert.
                    ->action(fn (AttachAction $action, array $data, Table $table) => AttachesThroughAssociations::attach($action, $this->getOwnerRecord(), $table, $data, ownerIsParent: false)),
            ])
            ->recordActions([
                DetachAction::make(),
            ]);
    }
}
