<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Filament\Resources\SalesPlaybookResource\Pages\CreateSalesPlaybook;
use Odden\Filament\Resources\SalesPlaybookResource\Pages\EditSalesPlaybook;
use Odden\Filament\Resources\SalesPlaybookResource\Pages\ListSalesPlaybooks;
use Odden\Sales\Models\SalesPlaybook;
use UnitEnum;

class SalesPlaybookResource extends Resource
{
    protected static ?string $model = SalesPlaybook::class;

    protected static ?string $modelLabel = 'playbook';

    protected static ?string $pluralModelLabel = 'Playbooks';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BookOpen;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Playbook overview')
                    ->schema([
                        TextInput::make('name')
                            ->label('Playbook name')
                            ->placeholder('e.g. BANT Enterprise Qualification')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label('URL slug')
                            ->required()
                            ->maxLength(255),
                        Select::make('category')
                            ->label('Category')
                            ->options([
                                'qualification' => 'Lead / Deal Qualification',
                                'objection_handling' => 'Objection Handling Battlecard',
                                'discovery' => 'Technical Discovery',
                                'demo' => 'Product Demo Guide',
                            ])
                            ->default('qualification')
                            ->required(),
                        Select::make('framework')
                            ->label('Framework')
                            ->options([
                                'bant' => 'BANT (Budget, Authority, Need, Timeline)',
                                'meddic' => 'MEDDIC (Metrics, Econ Buyer, Criteria, Process, Pain, Champion)',
                                'battlecard' => 'Competitor Battlecard',
                                'custom' => 'Custom Discovery',
                            ])
                            ->default('custom')
                            ->required(),
                        Textarea::make('description')
                            ->label('Description & purpose')
                            ->placeholder('Questions and guidance for reps to qualify deal momentum...')
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Active playbook')
                            ->default(true),
                    ])
                    ->columns(2),

                Section::make('Interactive checklist & discovery questions')
                    ->schema([
                        Repeater::make('questions')
                            ->label('Playbook prompts')
                            ->schema([
                                TextInput::make('id')
                                    ->label('Field key')
                                    ->placeholder('e.g. budget_status')
                                    ->required(),
                                TextInput::make('label')
                                    ->label('Question / prompt for rep')
                                    ->placeholder('e.g. What is the approved budget range?')
                                    ->required(),
                                Select::make('type')
                                    ->label('Response type')
                                    ->options([
                                        'text' => 'Short Text',
                                        'textarea' => 'Multi-line Notes',
                                        'select' => 'Dropdown Options',
                                    ])
                                    ->default('text')
                                    ->required(),
                                TextInput::make('target_property')
                                    ->label('Sync to target property (optional)')
                                    ->placeholder('e.g. qualification_budget'),
                            ])
                            ->columns(2)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Playbook')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('framework')
                    ->label('Framework')
                    ->badge()
                    ->color('primary')
                    ->formatStateUsing(fn ($state): string => strtoupper((string) $state)),
                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucwords(str_replace('_', ' ', (string) $state))),
                TextColumn::make('questions_count')
                    ->label('Questions')
                    ->getStateUsing(fn (SalesPlaybook $record): int => count($record->questions ?? []))
                    ->badge()
                    ->color('gray'),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
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
            'index' => ListSalesPlaybooks::route('/'),
            'create' => CreateSalesPlaybook::route('/create'),
            'edit' => EditSalesPlaybook::route('/{record}/edit'),
        ];
    }
}
