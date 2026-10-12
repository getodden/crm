<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\PipelineResource\RelationManagers;

use Closure;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use Odden\Sales\Enums\StageAutomationActionType;

class StagesRelationManager extends RelationManager
{
    protected static string $relationship = 'stages';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $title = 'Pipeline Stages';

    protected static bool $isLazy = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label('Stage Name')
                ->placeholder('e.g. Discovery Call')
                ->required()
                ->maxLength(255),
            TextInput::make('code')
                ->label('Internal Code')
                ->placeholder('e.g. discovery_call')
                ->required()
                // Codes are unique within a pipeline.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('pipeline_id', $this->getOwnerRecord()->getKey()),
                )
                ->maxLength(100),
            TextInput::make('probability')
                ->label('Win Probability (%)')
                ->numeric()
                ->default(0)
                ->minValue(0)
                ->maxValue(100)
                ->required(),
            TextInput::make('sort_order')
                ->label('Sort Order')
                ->numeric()
                ->default(10)
                ->required(),
            TextInput::make('rot_after_days')
                ->label('Rot After Days')
                ->helperText('Flag deals as idle/stale after spending this many days in this stage.')
                ->numeric()
                ->minValue(1)
                ->placeholder('e.g. 14')
                ->nullable(),
            // A stage closes a deal as won or as lost, not both.
            Toggle::make('is_closed_won')
                ->label('Closed Won Stage')
                ->helperText('Deals entering this stage will automatically be marked as Won.')
                ->live()
                ->rule(fn (callable $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                    if ($value && $get('is_closed_lost')) {
                        $fail('A stage cannot be both Closed Won and Closed Lost.');
                    }
                }),
            Toggle::make('is_closed_lost')
                ->label('Closed Lost Stage')
                ->helperText('Deals entering this stage will automatically be marked as Lost.')
                ->live(),
            Repeater::make('automations')
                ->relationship('automations')
                ->label('Stage Transition Automations & Guards')
                ->schema([
                    TextInput::make('name')
                        ->label('Rule Name')
                        ->placeholder('e.g. Require Associated Contact')
                        ->required()
                        ->columnSpan(2),
                    Select::make('action_type')
                        ->label('Automation Type')
                        ->options(collect(StageAutomationActionType::cases())->mapWithKeys(
                            fn (StageAutomationActionType $t) => [$t->value => $t->label()]
                        ))
                        ->required()
                        ->live()
                        ->columnSpan(2),
                    TextInput::make('action_payload.task_subject')
                        ->label('Task Subject')
                        ->placeholder('Defaults to "Follow up: <deal> in [<stage>]"')
                        ->maxLength(255)
                        ->visible(fn (callable $get): bool => $get('action_type') === StageAutomationActionType::CreateTask->value)
                        ->columnSpan(3),
                    TextInput::make('action_payload.due_days')
                        ->label('Due In (days)')
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->maxValue(365)
                        ->placeholder('2')
                        ->visible(fn (callable $get): bool => $get('action_type') === StageAutomationActionType::CreateTask->value)
                        ->columnSpan(2),
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ])
                ->columns(5)
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('sort_order')
                    ->label('#')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('Stage')
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('code')
                    ->label('Code')
                    ->badge(),
                TextColumn::make('probability')
                    ->label('Win Probability')
                    ->suffix('%')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 80 => 'success',
                        $state >= 40 => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('rot_after_days')
                    ->label('Stale After')
                    ->suffix(' days')
                    ->placeholder('No limit')
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_closed_won')
                    ->label('Won')
                    ->boolean(),
                IconColumn::make('is_closed_lost')
                    ->label('Lost')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label('Add Stage'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
