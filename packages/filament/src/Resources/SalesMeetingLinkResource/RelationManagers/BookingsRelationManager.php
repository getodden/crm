<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\SalesMeetingLinkResource\RelationManagers;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Odden\Core\Support\UserModel;
use Odden\Filament\Resources\SalesMeetingLinkResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Enums\MeetingCancelledBy;
use Odden\Sales\Models\SalesMeetingBooking;

class BookingsRelationManager extends RelationManager
{
    protected static string $relationship = 'bookings';

    protected static ?string $title = 'Bookings';

    protected static bool $isLazy = false;

    public function table(Table $table): Table
    {
        return $table
            ->recordTitle(fn (SalesMeetingBooking $record): string => $record->invitee_name)
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->orderByRaw('CASE WHEN cancelled_at IS NULL AND ends_at > ? THEN 0 ELSE 1 END', [now()])
                ->orderByRaw('CASE WHEN cancelled_at IS NULL AND ends_at > ? THEN starts_at END ASC', [now()])
                ->orderBy('starts_at', 'desc'))
            ->columns([
                TextColumn::make('starts_at')
                    ->label('When')
                    ->formatStateUsing(fn (SalesMeetingBooking $record): string => $record->starts_at->copy()->setTimezone($record->timezone)->format('D, M j, Y g:i A').' ('.$record->timezone.')')
                    ->weight('bold'),
                TextColumn::make('invitee_name')->label('Invitee'),
                TextColumn::make('invitee_email')->label('Email')->copyable(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->state(fn (SalesMeetingBooking $record): string => match (true) {
                        $record->cancelled_at !== null => 'Cancelled',
                        $record->ends_at->isPast() => 'Past',
                        default => 'Upcoming',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'Cancelled' => 'danger',
                        'Past' => 'gray',
                        default => 'success',
                    }),
            ])
            ->recordActions([
                Action::make('cancelBooking')
                    ->label('Cancel booking')
                    ->icon(Heroicon::XCircle)
                    ->color('danger')
                    ->visible(fn (SalesMeetingBooking $record): bool => $record->cancelled_at === null && $record->ends_at->isFuture())
                    ->authorize(fn (SalesMeetingBooking $record): bool => OddenAuthorization::allows('update', $record->meetingLink, SalesMeetingLinkResource::class))
                    ->requiresConfirmation()
                    ->modalHeading('Cancel this booking?')
                    ->modalDescription('The invitee and the host are emailed a cancellation with a calendar update.')
                    ->modalSubmitActionLabel('Cancel booking')
                    ->form([
                        Textarea::make('reason')
                            ->label('Reason (optional, included in the emails)')
                            ->maxLength(1000)
                            ->rows(3),
                    ])
                    ->action(function (SalesMeetingBooking $record, array $data): void {
                        $reason = is_string($data['reason'] ?? null) ? trim($data['reason']) : '';

                        $record->cancel(
                            MeetingCancelledBy::Staff,
                            UserModel::displayName(auth()->user(), 'A team member'),
                            $reason !== '' ? $reason : null,
                        );

                        Notification::make()->title('Booking cancelled')->success()->send();
                    }),
            ]);
    }
}
