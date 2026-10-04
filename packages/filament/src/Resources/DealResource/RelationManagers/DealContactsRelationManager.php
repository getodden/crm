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
use Odden\Core\Enums\LifecycleStage;
use Odden\Filament\Support\AttachesThroughAssociations;

class DealContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $recordTitleAttribute = 'email';

    protected static ?string $title = 'Associated Contacts';

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
                    ->label('Contact')
                    ->searchable(['first_name', 'last_name', 'email'])
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label('Email')
                    ->copyable(),
                TextColumn::make('pivot.type')
                    ->label('Role on Deal')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucfirst((string) ($state ?? 'primary'))),
                TextColumn::make('lifecycle_stage')
                    ->label('Stage')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof LifecycleStage ? $state->label() : ((string) ($state ?? '—'))),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->form(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        Select::make('type')
                            ->label('Role on Deal')
                            ->options([
                                'primary' => 'Primary Decision Maker',
                                'champion' => 'Internal Champion',
                                'economic_buyer' => 'Economic Buyer',
                                'technical_evaluator' => 'Technical Evaluator',
                                'billing' => 'Billing / Procurement',
                                'influencer' => 'Influencer',
                                'other' => 'Other',
                            ])
                            ->default('primary')
                            ->required(),
                    ])
                    // Linked through AssociateRecordsAction (tenant guard, type and cardinality rules, events), not a raw pivot insert.
                    ->action(fn (AttachAction $action, array $data, Table $table) => AttachesThroughAssociations::attach($action, $this->getOwnerRecord(), $table, $data, ownerIsParent: true)),
            ])
            ->recordActions([
                DetachAction::make(),
            ]);
    }
}
