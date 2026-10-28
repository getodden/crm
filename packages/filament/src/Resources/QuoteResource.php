<?php

declare(strict_types=1);

namespace Odden\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
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
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Odden\Filament\Resources\QuoteResource\Pages\CreateQuote;
use Odden\Filament\Resources\QuoteResource\Pages\EditQuote;
use Odden\Filament\Resources\QuoteResource\Pages\ListQuotes;
use Odden\Filament\Resources\QuoteResource\Pages\ViewQuote;
use Odden\Filament\Support\AreaGuard;
use Odden\Filament\Support\EncryptedSearch;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Actions\AcceptQuoteAction;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Exceptions\QuoteNotAcceptableException;
use Odden\Sales\Exceptions\StageRequirementException;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;
use Odden\Sales\Support\Money;
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
                            ->relationship('deal', 'name', modifyQueryUsing: fn (Builder $query): Builder => $query->whereIn(
                                $query->getModel()->getQualifiedKeyName(),
                                OddenAuthorization::scoped(Deal::class)->select((new Deal)->getQualifiedKeyName()),
                            ))
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => EncryptedSearch::limitedAndSorted(
                                OddenAuthorization::scoped(Deal::class)->whereNameStartsWith($search),
                                ['name'],
                                50,
                            )->pluck('name', 'id')->all())
                            ->live()
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
                            ->disableOptionWhen(self::isAcceptedOption(...))
                            ->helperText('A quote becomes Accepted only through Accept & Sign, which records the signer and wins the deal.')
                            ->default(QuoteStatus::Draft->value)
                            ->required(),
                        DatePicker::make('expires_at')
                            ->label('Expiration Date')
                            ->default(now()->addDays(30)),
                        TextInput::make('discount_amount')
                            ->label('Discount Amount')
                            ->numeric()
                            ->prefix(fn (callable $get): string => self::currencyPrefix($get('deal_id')))
                            ->default(0.00),
                        TextInput::make('tax_amount')
                            ->label('Tax Amount')
                            ->numeric()
                            ->prefix(fn (callable $get): string => self::currencyPrefix($get('deal_id')))
                            ->default(0.00),
                        TextInput::make('total_amount')
                            ->label('Total Amount')
                            ->numeric()
                            ->prefix(fn (callable $get): string => self::currencyPrefix($get('deal_id')))
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
                                    ->prefix(fn (callable $get): string => self::currencyPrefix($get('../../deal_id')))
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
                                    ->minValue(0)
                                    ->maxValue(100)
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
                        AreaGuard::field(Textarea::make('notes'), Quote::class)
                            ->label('Customer Facing Notes')
                            ->helperText('Shown to the customer on the quote page.')
                            ->columnSpanFull(),
                        AreaGuard::field(Textarea::make('internal_notes'), Quote::class)
                            ->label('Internal Notes')
                            ->helperText('For your team only: never shown to the customer.')
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
                    ->searchable(query: EncryptedSearch::quotes())
                    ->sortable(query: EncryptedSearch::sortBy(['title'])),
                TextColumn::make('deal.name')
                    ->label('Deal')
                    ->searchable(query: EncryptedSearch::quotesOfDeals()),
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
                TrashedFilter::make(),
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
                    ->visible(fn (Quote $record): bool => $record->status !== QuoteStatus::Draft)
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
                RestoreAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * A deleted quote still opens (to be restored from its page).
     */
    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * A signed quote is read-only: the signature covers its items, discount and tax.
     */
    public static function canEdit(Model $record): bool
    {
        return ! ($record instanceof Quote && $record->status === QuoteStatus::Accepted) && parent::canEdit($record);
    }

    /**
     * Deleting a signed quote would remove the record of what the customer accepted.
     */
    public static function canDelete(Model $record): bool
    {
        return ! ($record instanceof Quote && $record->status === QuoteStatus::Accepted) && parent::canDelete($record);
    }

    /**
     * Accepted is shown (so a signed quote still reads correctly) but cannot be picked: accepting goes through
     * AcceptQuoteAction, which records the signer and wins the deal.
     */
    public static function isAcceptedOption(string $value): bool
    {
        return $value === QuoteStatus::Accepted->value;
    }

    /**
     * The amount prefix for a quote on the given deal: the deal's currency (a quote is always in its deal's currency).
     */
    public static function currencyPrefix(mixed $dealId): string
    {
        $currency = filled($dealId) ? Deal::query()->whereKey($dealId)->value('currency') : null;

        return Money::prefix(is_string($currency) ? $currency : null);
    }

    /**
     * Fill the fields a quote takes from its deal: the currency, and the deal owner as the quote's owner when none
     * is set. The deal must be one the user can see.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function fillFromDeal(array $data, ?Deal $deal = null): array
    {
        $deal ??= filled($data['deal_id'] ?? null)
            ? OddenAuthorization::find(DealResource::class, Deal::class, $data['deal_id'])
            : null;

        if ($deal === null) {
            return $data;
        }

        $data['currency'] = $deal->currency;
        $data['user_id'] ??= $deal->owner_id ?? OddenAuthorization::userId();

        return $data;
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
