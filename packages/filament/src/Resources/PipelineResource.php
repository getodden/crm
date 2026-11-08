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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Odden\Core\Actions\DeleteModelAction;
use Odden\Filament\Resources\PipelineResource\Pages\CreatePipeline;
use Odden\Filament\Resources\PipelineResource\Pages\EditPipeline;
use Odden\Filament\Resources\PipelineResource\Pages\ListPipelines;
use Odden\Filament\Resources\PipelineResource\RelationManagers\StagesRelationManager;
use Odden\Filament\Support\DeletesRecords;
use Odden\Sales\Models\Pipeline;
use UnitEnum;

class PipelineResource extends Resource
{
    protected static ?string $model = Pipeline::class;

    protected static ?string $modelLabel = 'pipeline';

    protected static ?string $pluralModelLabel = 'Pipelines';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Funnel;

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Pipeline details')
                    ->schema([
                        TextInput::make('name')
                            ->label('Pipeline name')
                            ->placeholder('e.g. Sales Pipeline')
                            ->required()
                            ->maxLength(255)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, Get $get, ?string $state, string $operation) => $operation === 'create' && blank($get('code'))
                                ? $set('code', Str::slug((string) $state, '_'))
                                : null),
                        Toggle::make('is_default')
                            ->label('Default pipeline')
                            ->helperText('Used by default when new deals are created without a pipeline. Making this the default unsets the previous one.'),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
                Section::make('Advanced')
                    ->collapsed()
                    ->schema([
                        TextInput::make('code')
                            ->label('Pipeline code')
                            ->helperText('Used by integrations and the API. Made from the name; change it only if something already uses a different code.')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->maxLength(100),
                    ])
                    ->columnSpanFull(),
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
                DeleteAction::make()->using(fn (Model $record): bool => app(DeleteModelAction::class)->execute($record))
                    ->hidden(fn (Pipeline $record): bool => $record->isInUse()),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->using(DeletesRecords::bulk()),
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
