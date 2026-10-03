<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Odden\Filament\Resources\QuoteResource\Pages\CreateQuote;
use Odden\Filament\Resources\QuoteResource\Pages\EditQuote;
use Odden\Filament\Resources\QuoteResource\Pages\ListQuotes;
use Odden\Filament\Resources\QuoteResource\Pages\ViewQuote;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Actions\AcceptQuoteAction;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Exceptions\QuoteNotAcceptableException;
use Odden\Sales\Exceptions\StageRequirementException;
use Odden\Sales\Models\Quote;
use UnitEnum;

class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static ?string $modelLabel = 'Quote';

    protected static ?string $pluralModelLabel = 'Quotes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::DocumentText;

    protected static UnitEnum|string|null $navigationGroup = 'Sales';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Quote Overview')
                    ->schema([
                        TextInput::make('quote_number')
                            ->label('Quote #')
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder('Auto-generated'),
                        Select::make('deal_id')
                            ->label('Associated Deal')
                            ->relationship('deal', 'name')
                            ->searchable()
                            ->required(),
                        TextInput::make('title')
                            ->label('Title')
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(2),
                        Select::make('status')
                            ->options(collect(QuoteStatus::cases())->mapWithKeys(
                                fn (QuoteStatus $s) => [$s->value => $s->label()]
                            ))
                            ->default(QuoteStatus::Draft->value)
                            ->required(),
                        DatePicker::make('expires_at')
                            ->label('Expiration Date')
                            ->default(now()->addDays(30)),
                        TextInput::make('discount_amount')
                            ->label('Discount Amount')
                            ->numeric()
                            ->prefix('$')
                            ->default(0.00),
                        TextInput::make('tax_amount')
                            ->label('Tax Amount')
                            ->numeric()
                            ->prefix('$')
                            ->default(0.00),
                        TextInput::make('total_amount')
                            ->label('Total Amount')
                            ->numeric()
                            ->prefix('$')
                            ->disabled()
                            ->dehydrated(false),
                    ])
                    ->columns(2),

                Section::make('Line Items')
                    ->schema([
                        Repeater::make('items')
                            ->relationship('items')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Product / Service')
                                    ->required()
                                    ->columnSpan(3),
                                TextInput::make('sku')
                                    ->label('SKU')
                                    ->columnSpan(1),
                                TextInput::make('unit_price')
                                    ->label('Unit Price')
                                    ->numeric()
                                    ->prefix('$')
                                    ->required()
                                    ->columnSpan(1),
                                TextInput::make('quantity')
                                    ->label('Qty')
                                    ->numeric()
                                    ->default(1)
                                    ->minValue(0.01)
                                    ->required()
                                    ->columnSpan(1),
                                TextInput::make('discount_percent')
                                    ->label('Disc %')
                                    ->numeric()
                                    ->default(0)
                                    ->suffix('%')
                                    ->columnSpan(1),
                            ])
                            ->columns(7)
                            ->orderColumn('sort_order')
                            ->columnSpanFull(),
                    ]),

                Section::make('Terms & Notes')
                    ->schema([
                        Textarea::make('terms')
                            ->label('Terms and Conditions')
                            ->columnSpanFull(),
                        Textarea::make('notes')
                            ->label('Customer Facing Notes')
                            ->columnSpanFull(),
                    ])
                    ->collapsible(),

                Section::make('Acceptance & Digital Signature')
                    ->schema([
                        DateTimePicker::make('accepted_at')
                            ->label('Accepted Timestamp')
                            ->disabled(),
                        TextInput::make('signed_by_name')
                            ->label('Signer Name')
                            ->disabled(),
                        TextInput::make('signed_by_email')
                            ->label('Signer Email')
                            ->disabled(),
                        TextInput::make('public_token')
                            ->label('Public Client URL Token')
                            ->disabled(),
                    ])
                    ->columns(2)
                    ->collapsed(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('quote_number')
                    ->label('Quote #')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->badge(),
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('deal.name')
                    ->label('Deal')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->money(fn (Quote $record): string => $record->currency)
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof QuoteStatus ? $state->label() : ucfirst((string) $state))
                    ->color(fn ($state): string => match ($state instanceof QuoteStatus ? $state : QuoteStatus::tryFrom((string) $state)) {
                        QuoteStatus::Accepted => 'success',
                        QuoteStatus::Declined => 'danger',
                        QuoteStatus::Approved => 'warning',
                        QuoteStatus::Sent => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('expires_at')
                    ->label('Expires')
                    ->date()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(QuoteStatus::cases())->mapWithKeys(
                        fn (QuoteStatus $s) => [$s->value => $s->label()]
                    )),
            ])
            ->recordActions([
                Action::make('openPortal')
                    ->label('Portal')
                    ->icon(Heroicon::ArrowTopRightOnSquare)
                    ->color('info')
                    ->url(fn (Quote $record): string => route('odden.quotes.show', ['token' => $record->public_token]), shouldOpenInNewTab: true),
                Action::make('acceptQuote')
                    ->label('Accept & Sign')
                    ->icon(Heroicon::CheckBadge)
                    ->color('success')
                    ->visible(fn (Quote $record): bool => ! $record->status->isAccepted())
                    ->authorize(OddenAuthorization::forRecord('update', self::class))
                    ->form([
                        TextInput::make('signed_by_name')
                            ->label('Signer Full Name')
                            ->required(),
                        TextInput::make('signed_by_email')
                            ->label('Signer Email')
                            ->email()
                            ->required(),
                    ])
                    ->action(function (Quote $record, array $data): void {
                        try {
                            app(AcceptQuoteAction::class)->accept($record, (string) $data['signed_by_name'], (string) $data['signed_by_email']);
                        } catch (QuoteNotAcceptableException|StageRequirementException $e) {
                            Notification::make()
                                ->title('Quote Not Accepted')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Quote Accepted')
                            ->body("Quote {$record->quote_number} marked as accepted.")
                            ->success()
                            ->send();
                    }),
                ViewAction::make(),
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
            'index' => ListQuotes::route('/'),
            'create' => CreateQuote::route('/create'),
            'view' => ViewQuote::route('/{record}'),
            'edit' => EditQuote::route('/{record}/edit'),
        ];
    }
}
