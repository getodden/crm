<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Filament\Resources\PipelineResource\Pages\CreatePipeline;
use Odden\Filament\Resources\PipelineResource\Pages\EditPipeline;
use Odden\Filament\Resources\PipelineResource\Pages\ListPipelines;
use Odden\Filament\Resources\PipelineResource\RelationManagers\StagesRelationManager;
use Odden\Sales\Models\Pipeline;
use UnitEnum;

class PipelineResource extends Resource
{
    protected static ?string $model = Pipeline::class;

    protected static ?string $modelLabel = 'Pipeline';

    protected static ?string $pluralModelLabel = 'Pipelines';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Funnel;

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pipeline Details')
                    ->schema([
                        TextInput::make('name')
                            ->label('Pipeline Name')
                            ->placeholder('e.g. Sales Pipeline')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->label('Pipeline Code / Slug')
                            ->placeholder('e.g. sales_pipeline')
                            ->required()
                            ->maxLength(100),
                        Toggle::make('is_default')
                            ->label('Default Pipeline')
                            ->helperText('Used by default when new deals are created without a pipeline.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Pipeline')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('code')
                    ->label('Code')
                    ->badge(),
                TextColumn::make('stages_count')
                    ->counts('stages')
                    ->label('Stages')
                    ->badge()
                    ->color('info'),
                IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
                // A pipeline with deals or stage history can't be deleted (the model and database refuse it).
                DeleteAction::make()
                    ->hidden(fn (Pipeline $record): bool => $record->isInUse()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            StagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPipelines::route('/'),
            'create' => CreatePipeline::route('/create'),
            'edit' => EditPipeline::route('/{record}/edit'),
        ];
    }
}
