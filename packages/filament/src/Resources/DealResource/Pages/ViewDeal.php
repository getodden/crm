<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\DealResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Group;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Actions\ExecuteSalesPlaybookAction;
use Odden\Sales\Actions\GenerateQuoteFromDealAction;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\SalesPlaybook;
use Odden\Sales\Support\Money;

class ViewDeal extends ViewRecord
{
    protected static string $resource = DealResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('health_analysis')
                ->label(function (): string {
                    /** @var Deal $record */
                    $record = $this->getRecord();

                    return $record->getHealthScore()['badge_label'];
                })
                ->icon(Heroicon::Sparkles)
                ->color(function (): string {
                    /** @var Deal $record */
                    $record = $this->getRecord();

                    return $record->getHealthScore()['badge_color'];
                })
                ->modalHeading('Deal Health & Velocity Analysis')
                ->modalDescription('Multi-factor algorithmic scoring based on multi-threading, activity recency, stage dwell time, and CPQ quotes.')
                ->modalContent(function (): View {
                    /** @var Deal $record */
                    $record = $this->getRecord();

                    return view('odden-sales::deals.health-score-modal', [
                        'health' => $record->getHealthScore(),
                    ]);
                })
                ->modalSubmitAction(false)
                ->modalCancelActionLabel('Close'),

            Action::make('generate_quote')
                ->label('Generate Quote')
                ->authorize(OddenAuthorization::forRecord('update', DealResource::class))
                ->icon(Heroicon::DocumentText)
                ->color('primary')
                ->action(function (): void {
                    /** @var Deal $record */
                    $record = $this->getRecord();
                    $quote = app(GenerateQuoteFromDealAction::class)->execute($record);

                    Notification::make()
                        ->title('Draft Quote Generated')
                        ->body("Proposal #{$quote->quote_number} generated for ".Money::format($quote->total_amount, $quote->currency).".")
                        ->success()
                        ->send();
                }),

            Action::make('run_playbook')
                ->label('Run Playbook')
                ->authorize(OddenAuthorization::forRecord('update', DealResource::class))
                ->icon(Heroicon::BookOpen)
                ->color('gray')
                ->form(function (): array {
                    $playbooks = SalesPlaybook::query()->where('is_active', true)->get();
                    if ($playbooks->isEmpty()) {
                        return [
                            TextInput::make('no_playbooks')
                                ->label('Notice')
                                ->disabled()
                                ->default('No active playbooks found. Create one in Sales > Playbooks first.'),
                        ];
                    }

                    return [
                        Select::make('playbook_id')
                            ->label('Select Playbook')
                            ->options($playbooks->pluck('name', 'id'))
                            ->required()
                            ->live(),
                        Group::make()
                            ->schema(function (callable $get): array {
                                $playbookId = $get('playbook_id');
                                if (! $playbookId) {
                                    return [];
                                }

                                /** @var SalesPlaybook|null $playbook */
                                $playbook = SalesPlaybook::find($playbookId);
                                if (! $playbook || empty($playbook->questions)) {
                                    return [];
                                }

                                $fields = [];
                                foreach ($playbook->questions as $q) {
                                    $id = 'answers.'.$q['id'];
                                    $label = $q['label'];
                                    if ($q['type'] === 'textarea') {
                                        $fields[] = Textarea::make($id)->label($label)->rows(3);
                                    } else {
                                        $fields[] = TextInput::make($id)->label($label);
                                    }
                                }

                                return $fields;
                            }),
                    ];
                })
                ->action(function (array $data): void {
                    if (empty($data['playbook_id'])) {
                        return;
                    }

                    /** @var Deal $record */
                    $record = $this->getRecord();
                    /** @var SalesPlaybook $playbook */
                    $playbook = SalesPlaybook::findOrFail($data['playbook_id']);
                    $answers = isset($data['answers']) && is_array($data['answers']) ? $data['answers'] : [];

                    app(ExecuteSalesPlaybookAction::class)->execute($record, $playbook, $answers, OddenAuthorization::userId());

                    Notification::make()
                        ->title('Playbook Completed')
                        ->body("Recorded [{$playbook->name}] discovery notes on {$record->name}.")
                        ->success()
                        ->send();
                }),

            Action::make('mark_won')
                ->label('Mark Won')
                ->icon(Heroicon::CheckCircle)
                ->color('success')
                ->visible(fn (): bool => $this->getRecord() instanceof Deal && $this->getRecord()->status !== DealStatus::Won)
                ->authorize(OddenAuthorization::forRecord('update', DealResource::class))
                ->action(function (): void {
                    /** @var Deal $record */
                    $record = $this->getRecord();
                    $record->markWon(OddenAuthorization::userId());

                    Notification::make()
                        ->title('Deal Won!')
                        ->body("Deal [{$record->name}] has been marked as Closed Won.")
                        ->success()
                        ->send();
                }),

            Action::make('mark_lost')
                ->label('Mark Lost')
                ->icon(Heroicon::XCircle)
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord() instanceof Deal && $this->getRecord()->status !== DealStatus::Lost)
                ->authorize(OddenAuthorization::forRecord('update', DealResource::class))
                ->form([
                    Textarea::make('lost_reason')
                        ->label('Reason for Loss')
                        ->placeholder('e.g. Budget cuts, chose competitor...')
                        ->required(),
                ])
                ->action(function (array $data): void {
                    /** @var Deal $record */
                    $record = $this->getRecord();
                    $record->markLost($data['lost_reason'] ?? null, OddenAuthorization::userId());

                    Notification::make()
                        ->title('Deal Closed as Lost')
                        ->body("Deal [{$record->name}] marked as Lost.")
                        ->warning()
                        ->send();
                }),

            EditAction::make(),
        ];
    }
}
