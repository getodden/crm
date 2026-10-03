<?php

declare(strict_types=1);

namespace Odden\Filament\Resources\DealResource\Pages;

use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Actions\CalculatePipelineForecastAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;

class KanbanDeals extends Page
{
    protected static string $resource = DealResource::class;

    protected static ?string $title = 'Deals Pipeline Board';

    protected static ?string $navigationLabel = 'Pipeline Board';

    protected string $view = 'odden-filament::pages.deal-kanban';

    public ?int $pipelineId = null;

    /**
     * @param  array<string, mixed>  $parameters
     */
    public static function canAccess(array $parameters = []): bool
    {
        return OddenAuthorization::canViewAny([DealResource::class]);
    }

    public function mount(): void
    {
        /** @var Pipeline|null $defaultPipeline */
        $defaultPipeline = Pipeline::query()->default()->first() ?? Pipeline::query()->first();

        $this->pipelineId = $defaultPipeline?->id;
    }

    /**
     * @return Collection<int, Pipeline>
     */
    public function getPipelinesProperty(): Collection
    {
        return Pipeline::query()->orderBy('name')->get();
    }

    /**
     * @return Collection<int, PipelineStage>
     */
    public function getStagesProperty(): Collection
    {
        if (! $this->pipelineId) {
            return new Collection;
        }

        return PipelineStage::query()
            ->where('pipeline_id', $this->pipelineId)
            ->with(['deals' => fn ($q) => $q
                ->whereIn((new Deal)->getQualifiedKeyName(), DealResource::getEloquentQuery()->select((new Deal)->getQualifiedKeyName()))
                ->with(['contacts', 'companies', 'stageHistory', 'stage'])
                ->orderBy('created_at', 'desc')])
            ->orderBy('sort_order')
            ->get();
    }

    public function getTotalPipelineValueProperty(): float
    {
        if (! $this->pipelineId) {
            return 0.0;
        }

        return (float) OddenAuthorization::query(DealResource::class, Deal::class)
            ->where('pipeline_id', $this->pipelineId)
            ->where('status', 'open')
            ->sum('amount');
    }

    /**
     * @return array{
     *     open_value: float,
     *     open_count: int,
     *     weighted_forecast: float,
     *     won_value: float,
     *     won_count: int,
     *     lost_count: int,
     *     win_rate: float,
     *     average_deal_size: float,
     *     lost_reasons: array<string, int>,
     *     stale_deals_count: int
     * }
     */
    public function getForecastProperty(): array
    {
        return app(CalculatePipelineForecastAction::class)->execute($this->pipelineId);
    }

    public function moveDeal(int $dealId, int $stageId): void
    {
        $deal = OddenAuthorization::findAndAuthorize(DealResource::class, Deal::class, $dealId, 'update');

        /** @var PipelineStage $stage */
        $stage = PipelineStage::query()->findOrFail($stageId);

        if ((int) $stage->pipeline_id !== (int) $deal->pipeline_id) {
            Notification::make()
                ->title('Deal Not Moved')
                ->body("[{$stage->name}] belongs to a different pipeline.")
                ->danger()
                ->send();

            return;
        }

        $deal->moveToStage($stage, OddenAuthorization::userId());

        Notification::make()
            ->title('Deal Updated')
            ->body("Moved to [{$stage->name}]")
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('table')
                ->label('Table View')
                ->icon(Heroicon::TableCells)
                ->color('gray')
                ->url(DealResource::getUrl('index')),
            Action::make('create')
                ->label('New Deal')
                ->icon(Heroicon::Plus)
                ->color('primary')
                ->url(DealResource::getUrl('create')),
        ];
    }
}
