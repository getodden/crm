<?php

declare(strict_types=1);

namespace Odden\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Odden\Sales\Actions\CalculatePipelineForecastAction;
use Odden\Sales\Support\Money;

class DealPipelineForecastWidget extends StatsOverviewWidget
{
    public ?int $pipelineId = null;

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $forecast = app(CalculatePipelineForecastAction::class)->execute($this->pipelineId);

        return [
            Stat::make('Open Pipeline', Money::format($forecast['open_value']))
                ->description("{$forecast['open_count']} active open deals")
                ->icon(Heroicon::CurrencyDollar)
                ->color('info'),

            Stat::make('Weighted Forecast', Money::format($forecast['weighted_forecast']))
                ->description('Probability-weighted revenue')
                ->icon(Heroicon::ChartBar)
                ->color('primary'),

            Stat::make('Closed Won', Money::format($forecast['won_value']))
                ->description("{$forecast['won_count']} deals closed won")
                ->icon(Heroicon::CheckCircle)
                ->color('success'),

            Stat::make('Win Rate', "{$forecast['win_rate']}%")
                ->description('Avg size: '.Money::format($forecast['average_deal_size'], decimals: 0))
                ->icon(Heroicon::ArrowTrendingUp)
                ->color($forecast['win_rate'] >= 50.0 ? 'success' : 'warning'),
        ];
    }
}
