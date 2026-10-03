<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\DealResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Resources\QuoteResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Actions\AcceptQuoteAction;
use Odden\Sales\Actions\GenerateQuoteFromDealAction;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Exceptions\QuoteNotAcceptableException;
use Odden\Sales\Exceptions\StageRequirementException;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Quote;

class QuotesRelationManager extends RelationManager
{
    protected static string $relationship = 'quotes';

    protected static ?string $recordTitleAttribute = 'quote_number';

    protected static ?string $title = 'Quotes & Proposals';

    protected static bool $isLazy = false;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->label('Quote Title')
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
                ->label('Discount ($)')
                ->numeric()
                ->default(0.00),
            TextInput::make('tax_amount')
                ->label('Tax ($)')
                ->numeric()
                ->default(0.00),
            Textarea::make('terms')
                ->label('Payment Terms')
                ->default('Payment due net 30 days from signature.')
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label('Internal Notes')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('quote_number')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('quote_number')
                    ->label('Quote #')
                    ->weight('bold')
                    ->badge(),
                TextColumn::make('title')
                    ->label('Title')
                    ->searchable(),
                TextColumn::make('total_amount')
                    ->label('Total')
                    ->weight('bold')
                    ->money(fn (): string => $this->getOwnerRecord()->currency ?? 'USD'),
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
                    ->date(),
                TextColumn::make('signed_by_name')
                    ->label('Signed By')
                    ->placeholder('-'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->date()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('generateFromDeal')
                    ->label('Generate from Products')
                    ->authorize(fn (): bool => OddenAuthorization::allows('update', $this->getOwnerRecord(), DealResource::class))
                    ->icon(Heroicon::DocumentPlus)
                    ->color('primary')
                    ->action(function (): void {
                        /** @var Deal $deal */
                        $deal = $this->getOwnerRecord();

                        $quote = app(GenerateQuoteFromDealAction::class)->execute($deal);

                        Notification::make()
                            ->title('Quote Generated')
                            ->body("Created {$quote->quote_number} with {$quote->items()->count()} line items.")
                            ->success()
                            ->send();
                    }),
                CreateAction::make()->label('New Blank Quote'),
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
                    ->authorize(fn (Quote $record): bool => OddenAuthorization::allows('update', $record, QuoteResource::class)
                        && OddenAuthorization::allows('update', $this->getOwnerRecord(), DealResource::class))
                    ->schema([
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
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }
}
