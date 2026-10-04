<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
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
use Odden\Core\Models\CrmList;
use Odden\Filament\Resources\AdAudienceSyncResource\Pages\CreateAdAudienceSync;
use Odden\Filament\Resources\AdAudienceSyncResource\Pages\EditAdAudienceSync;
use Odden\Filament\Resources\AdAudienceSyncResource\Pages\ListAdAudienceSyncs;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Marketing\Contracts\PublishesAdAudience;
use Odden\Marketing\Models\AdAudienceSync;
use UnitEnum;

class AdAudienceSyncResource extends Resource
{
    protected static ?string $model = AdAudienceSync::class;

    protected static ?string $modelLabel = 'Ad Audience Sync';

    protected static ?string $pluralModelLabel = 'Ad Audience Sync Bridges';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ArrowPathRoundedSquare;

    protected static UnitEnum|string|null $navigationGroup = 'Marketing';

    protected static ?int $navigationSort = 8;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Ad Network Integration')
                    ->description('Bridge CRM segmentation lists with ad network custom audience targeting (SHA-256 hashed).')
                    ->schema([
                        TextInput::make('name')
                            ->label('Bridge Name')
                            ->placeholder('e.g. LinkedIn Matched Audiences: Surging Tier 1 Accounts')
                            ->required()
                            ->maxLength(255),
                        Select::make('platform')
                            ->label('Advertising Platform')
                            ->options([
                                'linkedin' => 'LinkedIn Ads (Matched Audiences)',
                                'google' => 'Google Ads (Customer Match)',
                                'meta' => 'Meta Ads (Custom Audiences)',
                            ])
                            ->default('linkedin')
                            ->required(),
                        Select::make('list_id')
                            ->label('CRM Source Audience List')
                            ->options(fn (): array => CrmList::query()->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                        TextInput::make('audience_id')
                            ->label('Remote Ad Account Audience ID (Optional)')
                            ->placeholder('e.g. act_108429104 or lnkd_aud_982'),
                        Toggle::make('is_active')
                            ->label('Active Synchronization')
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
                    ->label('Bridge Name')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('platform')
                    ->label('Ad Platform')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'linkedin' => 'LinkedIn Ads',
                        'google' => 'Google Ads',
                        'meta' => 'Meta Ads',
                        default => ucfirst($state),
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'linkedin' => 'info',
                        'google' => 'danger',
                        'meta' => 'primary',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('list.name')
                    ->label('Source List')
                    ->sortable(),
                TextColumn::make('records_count')
                    ->label('Matched Records')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('last_synced_at')
                    ->label('Last Synced')
                    ->dateTime('M j, Y g:i A')
                    ->sortable(),
                IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->actions([
                Action::make('syncNow')
                    ->label('Sync Now')
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->icon(Heroicon::ArrowPath)
                    ->color('success')
                    ->action(function (AdAudienceSync $record): void {
                        $result = app(PublishesAdAudience::class)->execute($record);

                        Notification::make()
                            ->title('Ad Audience Synchronized')
                            ->body($result['message'] ?? "Generated SHA-256 privacy hashes for {$result['records_synced']} contacts on {$result['platform']}.")
                            ->success()
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
            'index' => ListAdAudienceSyncs::route('/'),
            'create' => CreateAdAudienceSync::route('/create'),
            'edit' => EditAdAudienceSync::route('/{record}/edit'),
        ];
    }
}
