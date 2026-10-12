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
                ->label('Process Due Cadences')
                ->icon(Heroicon::Play)
                ->color('info')
                // Processes due steps across every active sequence, so it needs `update` on all of them.
                ->authorize(fn (): bool => OddenAuthorization::query(SalesSequenceResource::class, SalesSequence::class)
                    ->where('is_active', true)
                    ->get()
                    ->every(fn (SalesSequence $sequence): bool => OddenAuthorization::allows('update', $sequence, SalesSequenceResource::class)))
                ->action(function (): void {
                    // Only the sequences this user can see, so a scoped user cannot run other teams' cadences.
                    $visible = OddenAuthorization::query(SalesSequenceResource::class, SalesSequence::class)->pluck('id')->map(fn (mixed $id): int|string => is_int($id) ? $id : (string) $id)->values()->all();
                    $stats = app(ProcessCadencesAction::class)->execute($visible);

                    Notification::make()
                        ->title('Cadences Processed')
                        ->body("Processed {$stats['processed']} enrollments ({$stats['emails_sent']} emails sent, {$stats['tasks_created']} tasks created).")
                        ->success()
                        ->send();
                }),
            CreateAction::make(),
        ];
    }
}
