<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
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
use Odden\Filament\Resources\SalesEmailTemplateResource\Pages\CreateSalesEmailTemplate;
use Odden\Filament\Resources\SalesEmailTemplateResource\Pages\EditSalesEmailTemplate;
use Odden\Filament\Resources\SalesEmailTemplateResource\Pages\ListSalesEmailTemplates;
use Odden\Sales\Models\SalesEmailTemplate;
use UnitEnum;

class SalesEmailTemplateResource extends Resource
{
    protected static ?string $model = SalesEmailTemplate::class;

    protected static ?string $modelLabel = 'sales email template';

    protected static ?string $pluralModelLabel = 'Sales email templates';

    protected static bool $hasTitleCaseModelLabel = false;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Envelope;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Template Details')
                    ->schema([
                        TextInput::make('name')
                            ->label('Template name')
                            ->placeholder('e.g. Post-Demo Follow-Up')
                            ->required()
                            ->maxLength(255),
                        Select::make('category')
                            ->label('Category')
                            ->options([
                                'introduction' => 'Cold Outreach / Intro',
                                'follow_up' => 'Follow Up',
                                'proposal' => 'Proposal / Pricing',
                                'closing' => 'Closing / Negotiation',
                                'general' => 'General Sales',
                            ])
                            ->default('follow_up')
                            ->required(),
                        TextInput::make('subject')
                            ->label('Email subject')
                            ->placeholder('e.g. Next steps for {{ company.name }}')
                            ->helperText('Available merge tags: {{ contact.first_name }}, {{ contact.last_name }}, {{ deal.name }}, {{ deal.amount }}, {{ company.name }}')
                            ->required()
                            ->columnSpanFull(),
                        Textarea::make('body_html')
                            ->label('Email body')
                            ->rows(8)
                            ->required()
                            ->helperText('Use merge tags like {{ contact.first_name }} to personalize dynamic values.')
                            ->columnSpanFull(),
                        Toggle::make('is_shared')
                            ->label('Shared with sales team')
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
                    ->label('Template')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('Category')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ucwords(str_replace('_', ' ', (string) $state))),
                TextColumn::make('subject')
                    ->label('Subject')
                    ->searchable()
                    ->limit(40),
                IconColumn::make('is_shared')
                    ->label('Shared')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
            'index' => ListSalesEmailTemplates::route('/'),
            'create' => CreateSalesEmailTemplate::route('/create'),
            'edit' => EditSalesEmailTemplate::route('/{record}/edit'),
        ];
    }
}
