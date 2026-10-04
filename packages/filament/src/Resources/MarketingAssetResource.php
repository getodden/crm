<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Filament\Resources\MarketingAssetResource\Pages\CreateMarketingAsset;
use Odden\Filament\Resources\MarketingAssetResource\Pages\EditMarketingAsset;
use Odden\Filament\Resources\MarketingAssetResource\Pages\ListMarketingAssets;
use Odden\Marketing\Models\MarketingAsset;
use UnitEnum;

class MarketingAssetResource extends Resource
{
    protected static ?string $model = MarketingAsset::class;

    protected static ?string $modelLabel = 'Marketing Asset';

    protected static ?string $pluralModelLabel = 'Marketing Assets';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowDownTray;

    protected static UnitEnum|string|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Asset Specification & Access')
                    ->schema([
                        TextInput::make('name')
                            ->label('Asset Name / Title')
                            ->placeholder('e.g. 2026 Enterprise SaaS Pricing Benchmark Report')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('slug')
                            ->label('URL Slug')
                            ->placeholder('saas-pricing-benchmark-report')
                            ->required()
                            ->maxLength(255),
                        Select::make('asset_type')
                            ->label('Asset Classification')
                            ->options([
                                'whitepaper' => 'Whitepaper',
                                'case_study' => 'Customer Case Study',
                                'guide' => 'E-Book / Industry Guide',
                                'template' => 'Operational Template / Checklist',
                                'spreadsheet' => 'Financial Model / Calculator',
                                'report' => 'State of the Industry Report',
                            ])
                            ->default('whitepaper')
                            ->required(),
                        TextInput::make('lead_score_points')
                            ->label('Lead Scoring Bonus (pts)')
                            ->numeric()
                            ->default(15)
                            ->required()
                            ->helperText('Points awarded to contact upon downloading this asset.'),
                        TextInput::make('external_url')
                            ->label('External Hosted Link (Google Drive / Figma / Notion)')
                            ->url()
                            ->placeholder('https://drive.google.com/...'),
                        TextInput::make('file_path')
                            ->label('Local Storage File Path')
                            ->placeholder('assets/reports/2026-report.pdf')
                            ->helperText('Relative to storage/app. Paths that leave that folder are refused.')
                            ->maxLength(255)
                            ->rules([
                                fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                                    if (is_string($value) && $value !== '' && (str_contains($value, '..') || str_starts_with($value, '/') || str_starts_with($value, '\\') || str_contains($value, "\0"))) {
                                        $fail('The file path must be inside storage/app (no "..", and not an absolute path).');
                                    }
                                },
                            ]),
                        Toggle::make('is_gated')
                            ->label('Gated Content (Requires form submission / email)')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Active & Available')
                            ->default(true),
                        Textarea::make('description')
                            ->label('Summary & Marketing Hook')
                            ->placeholder('Brief overview displayed on landing page copy and social preview...')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Asset Title')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('asset_type')
                    ->label('Type')
                    ->badge()
                    ->color('info')
                    ->sortable(),
                IconColumn::make('is_gated')
                    ->label('Gated')
                    ->boolean(),
                TextColumn::make('downloads_count')
                    ->label('Downloads')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('unique_leads_count')
                    ->label('Unique Leads')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('lead_score_points')
                    ->label('Score Bonus')
                    ->formatStateUsing(fn (int $state): string => "+{$state} pts")
                    ->badge()
                    ->color('success'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime('M j, Y')
                    ->sortable(),
            ])
            ->actions([
                Action::make('copyDownloadLink')
                    ->label('Copy URL')
                    ->icon(Heroicon::ClipboardDocument)
                    ->color('gray')
                    ->action(function (MarketingAsset $record): void {
                        Notification::make()
                            ->title('Gated Link Ready')
                            ->body($record->getDownloadUrl())
                            ->info()
                            ->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMarketingAssets::route('/'),
            'create' => CreateMarketingAsset::route('/create'),
            'edit' => EditMarketingAsset::route('/{record}/edit'),
        ];
    }
}
