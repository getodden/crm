<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\ContactResource\RelationManagers;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Actions\EnrollContactInSequenceAction;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

class SalesSequenceEnrollmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'salesSequenceEnrollments';

    protected static ?string $title = 'Sales sequences & cadences';

    protected static string|BackedEnum|null $icon = Heroicon::ArrowPath;

    public function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('sequence.name')
                    ->label('Cadence')
                    ->weight('bold')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('current_step')
                    ->label('Current progress')
                    ->formatStateUsing(function (SalesSequenceEnrollment $record): string {
                        $total = $record->sequence->totalSteps();
                        $steps = $record->sequence->steps ?? [];
                        $stepDef = $steps[$record->current_step - 1] ?? null;
                        $stepTitle = $stepDef['title'] ?? 'Active';

                        return "Step {$record->current_step} of {$total} ({$stepTitle})";
                    })
                    ->badge()
                    ->color('primary'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'completed' => 'info',
                        default => 'danger',
                    }),

                TextColumn::make('next_step_due_at')
                    ->label('Next touch due')
                    ->date('M j, Y')
                    ->placeholder('None (Complete)')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Enrolled on')
                    ->dateTime('M j, Y g:i A')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                Action::make('enrollInCadence')
                    ->label('Enroll in cadence')
                    ->authorize(fn (): bool => OddenAuthorization::allows('update', $this->getOwnerRecord(), ContactResource::class))
                    ->icon(Heroicon::UserPlus)
                    ->color('primary')
                    ->form([
                        Select::make('sequence_id')
                            ->label('Select active cadence')
                            ->options(fn (): array => SalesSequence::query()->where('is_active', true)->pluck('name', 'id')->all())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var Contact $contact */
                        $contact = $this->getOwnerRecord();
                        /** @var SalesSequence $sequence */
                        $sequence = SalesSequence::findOrFail($data['sequence_id']);

                        app(EnrollContactInSequenceAction::class)->execute($contact, $sequence);

                        Notification::make()
                            ->title('Enrolled in cadence')
                            ->body("Contact successfully enrolled in {$sequence->name}.")
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                Action::make('advanceStep')
                    ->label('Advance step')
                    ->icon(Heroicon::Forward)
                    ->color('info')
                    ->visible(fn (SalesSequenceEnrollment $record): bool => $record->status === 'active')
                    ->authorize(fn (SalesSequenceEnrollment $record): bool => OddenAuthorization::allows('update', $this->getOwnerRecord(), ContactResource::class)
                        && OddenAuthorization::allows('update', $record))
                    ->action(function (SalesSequenceEnrollment $record): void {
                        $record->advanceStep();

                        Notification::make()
                            ->title('Cadence step advanced')
                            ->body("Advanced to step {$record->current_step}.")
                            ->success()
                            ->send();
                    }),

                Action::make('unenroll')
                    ->label('Unenroll')
                    ->icon(Heroicon::XMark)
                    ->color('danger')
                    ->visible(fn (SalesSequenceEnrollment $record): bool => $record->status === 'active')
                    ->authorize(fn (SalesSequenceEnrollment $record): bool => OddenAuthorization::allows('update', $this->getOwnerRecord(), ContactResource::class)
                        && OddenAuthorization::allows('update', $record))
                    ->requiresConfirmation()
                    ->modalHeading('Unenroll from cadence')
                    ->modalDescription('Stop all scheduled outbound emails and tasks for this contact in this cadence.')
                    ->action(function (SalesSequenceEnrollment $record): void {
                        $record->update([
                            'status' => 'unenrolled',
                            'next_step_due_at' => null,
                        ]);

                        Notification::make()
                            ->title('Contact unenrolled')
                            ->body('Contact removed from cadence.')
                            ->warning()
                            ->send();
                    }),
            ]);
    }
}
