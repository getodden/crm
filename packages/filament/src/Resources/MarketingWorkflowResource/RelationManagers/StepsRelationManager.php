<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\MarketingWorkflowResource\RelationManagers;

use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Models\WorkflowStep;

class StepsRelationManager extends RelationManager
{
    protected static string $relationship = 'steps';

    protected static ?string $title = 'Workflow Execution Steps & Branching';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('step_number')
                    ->label('Step Sequence #')
                    ->numeric()
                    ->default(fn (): int => (WorkflowStep::query()->where('workflow_id', $this->getOwnerRecord()->getKey())->max('step_number') ?? 0) + 1)
                    ->required(),

                Select::make('type')
                    ->label('Action Type')
                    ->options(collect(WorkflowStepType::cases())->mapWithKeys(fn (WorkflowStepType $t): array => [$t->value => $t->label()])->all())
                    ->required()
                    ->reactive(),

                KeyValue::make('config')
                    ->label('Step Configuration')
                    ->helperText('Settings by action. Send email: template_id, subject, topic_id. Wait: delay_minutes (e.g. 2880 for two days). Condition: property, operator (>=, >, <=, <, !=, =), value. Webhook: url, method, secret. Any step can set next_step to jump to another step number.')
                    ->columnSpanFull(),

                TextInput::make('next_step_on_true')
                    ->label('Next step # if the condition is true')
                    ->numeric()
                    ->helperText('Condition steps only. Leave empty to continue to the next step number.'),

                TextInput::make('next_step_on_false')
                    ->label('Next step # if the condition is false')
                    ->numeric()
                    ->helperText('Condition steps only. Leave empty to end the workflow. Going back to an earlier step needs a Wait step in between, or the workflow stops.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('step_number')
            ->defaultSort('step_number', 'asc')
            ->columns([
                TextColumn::make('step_number')
                    ->label('#')
                    ->sortable()
                    ->weight('bold'),

                TextColumn::make('type')
                    ->label('Action')
                    ->badge()
                    ->color(fn (WorkflowStep $record): string => match ($record->type) {
                        WorkflowStepType::SendEmail => 'info',
                        WorkflowStepType::SendSms => 'success',
                        WorkflowStepType::Delay => 'warning',
                        WorkflowStepType::Condition => 'primary',
                        WorkflowStepType::CreateDeal, WorkflowStepType::CreateSalesTask => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('config')
                    ->label('Parameters')
                    ->formatStateUsing(function (?array $state): string {
                        if (empty($state)) {
                            return 'Default';
                        }

                        return collect($state)->map(fn ($v, $k): string => "{$k}: {$v}")->implode(', ');
                    }),

                TextColumn::make('next_step_on_true')
                    ->label('Next (True)')
                    ->placeholder('Step + 1'),

                TextColumn::make('next_step_on_false')
                    ->label('Next (False)')
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()->label('Add Workflow Step'),
            ])
            ->actions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
