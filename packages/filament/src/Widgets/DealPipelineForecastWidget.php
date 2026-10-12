<?php

declare(strict_types=1);

namespace Odden\Filament\Widgets;

use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Odden\Filament\Resources\DealResource;
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
        $forecast = app(CalculatePipelineForecastAction::class)->execute(
            $this->pipelineId,
            visibleDeals: DealResource::getEloquentQuery(),
        );

        $currency = $forecast['currency'];
        $others = $forecast['other_currencies'];
        $otherNote = $others === []
            ? null
            : 'Not included: '.implode(', ', array_map(
                fn (string $code, array $totals): string => Money::format($totals['open_value'], $code).' open',
                array_keys($others),
                $others,
            ));

        return [
            Stat::make('Open Pipeline', Money::format($forecast['open_value'], $currency))
                ->description("{$forecast['open_count']} active open deals".($otherNote !== null ? " · {$otherNote}" : ''))
                ->icon(Heroicon::CurrencyDollar)
                ->color('info'),

            Stat::make('Weighted Forecast', Money::format($forecast['weighted_forecast'], $currency))
                ->description('Probability-weighted revenue')
                ->icon(Heroicon::ChartBar)
                ->color('primary'),

            Stat::make('Closed Won', Money::format($forecast['won_value'], $currency))
                ->description("{$forecast['won_count']} deals closed won")
                ->icon(Heroicon::CheckCircle)
                ->color('success'),

            Stat::make('Win Rate', "{$forecast['win_rate']}%")
                ->description('Avg size: '.Money::format($forecast['average_deal_size'], $currency, 0))
                ->icon(Heroicon::ArrowTrendingUp)
                ->color($forecast['win_rate'] >= 50.0 ? 'success' : 'warning'),
        ];
    }
}
