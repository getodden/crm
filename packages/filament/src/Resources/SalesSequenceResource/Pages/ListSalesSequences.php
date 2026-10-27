<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\SalesSequenceResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Odden\Filament\Resources\SalesSequenceResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Actions\ProcessCadencesAction;
use Odden\Sales\Models\SalesSequence;

class ListSalesSequences extends ListRecords
{
    protected static string $resource = SalesSequenceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('processDueCadences')
                ->label('Run due steps now')
                ->icon(Heroicon::Play)
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Run due sequence steps now?')
                ->modalDescription(function (): string {
                    $due = app(ProcessCadencesAction::class)->due($this->visibleSequenceIds())->count();

                    return $due === 0
                        ? 'No contact has a step due right now, so nothing would be sent.'
                        : trans_choice('{1} 1 contact has a step due. Its email step is sent at once and its task step becomes a task.|[2,*] :count contacts have a step due. Their email steps are sent at once and their task steps become tasks.', $due, ['count' => number_format($due)]).' Emails can\'t be recalled once sent.';
                })
                ->modalSubmitActionLabel('Run now')
                // Processes due steps across every active sequence, so it needs `update` on all of them.
                ->authorize(fn (): bool => OddenAuthorization::query(SalesSequenceResource::class, SalesSequence::class)
                    ->where('is_active', true)
                    ->get()
                    ->every(fn (SalesSequence $sequence): bool => OddenAuthorization::allows('update', $sequence, SalesSequenceResource::class)))
                ->action(function (): void {
                    $stats = app(ProcessCadencesAction::class)->execute($this->visibleSequenceIds());

                    Notification::make()
                        ->title('Due steps run')
                        ->body("Processed {$stats['processed']} enrollments ({$stats['emails_sent']} emails sent, {$stats['tasks_created']} tasks created).")
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }

    /**
     * The sequences this user can see, so a scoped user cannot run (or count) other teams' cadences.
     *
     * @return array<int, int|string>
     */
    private function visibleSequenceIds(): array
    {
        return OddenAuthorization::query(SalesSequenceResource::class, SalesSequence::class)->pluck('id')->map(fn (mixed $id): int|string => is_int($id) ? $id : (string) $id)->values()->all();
    }
}
