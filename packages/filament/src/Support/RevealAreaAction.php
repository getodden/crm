<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\RevealNotAllowed;

/**
 * "View with a reason": a person whose role allows it opens the areas they do not hold, for the record in front of them only. They give
 * a reason, the host is told (AreaRevealed) so it can log it and tell the owners, and the page reloads with the data in view.
 */
final class RevealAreaAction
{
    public static function make(): Action
    {
        return Action::make('revealAreas')
            ->label('View with a reason')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->visible(static function (?Model $record): bool {
                if ($record === null) {
                    return false;
                }

                $areas = app(Areas::class);

                foreach ($areas->hiddenOn($record) as $area) {
                    if ($areas->mayReveal($area)) {
                        return true;
                    }
                }

                return false;
            })
            ->modalHeading('View hidden data with a reason')
            ->modalDescription('Some of this record is outside your areas. Say why you need it: the reason is recorded and the workspace owners can see it. It opens for this record only, for a short time.')
            ->schema([
                Textarea::make('reason')
                    ->label('Reason')
                    ->required()
                    ->minLength(Areas::MIN_REASON)
                    ->maxLength(500)
                    ->rows(3),
            ])
            ->modalSubmitActionLabel('Show it')
            ->action(static function (array $data, Model $record, $livewire): void {
                $areas = app(Areas::class);

                try {
                    foreach ($areas->hiddenOn($record) as $area) {
                        if ($areas->mayReveal($area)) {
                            $areas->reveal($area, $record, (string) $data['reason']);
                        }
                    }
                } catch (RevealNotAllowed $e) {
                    Notification::make()->title($e->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title('Shown for this record, and recorded')->success()->send();
                $livewire->redirect(request()->header('Referer') ?: url()->current());
            });
    }
}
