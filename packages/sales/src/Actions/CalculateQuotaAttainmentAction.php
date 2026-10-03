<?php

declare(strict_types=1);

namespace Odden\Sales\Actions;

use Odden\Sales\Models\Deal;
use Odden\Sales\Models\SalesQuota;

class CalculateQuotaAttainmentAction
{
    /**
     * Calculate quota attainment, pacing, and pipeline coverage.
     *
     * @return array{
     *     target_amount: float,
     *     won_amount: float,
     *     attainment_percent: float,
     *     gap_to_target: float,
     *     open_pipeline_amount: float,
     *     coverage_ratio: float
     * }
     */
    public function execute(SalesQuota $quota, ?int $teamId = null): array
    {
        $dealsTable = config('odden-sales.tables.deals', 'odden_deals');

        $dealsQuery = Deal::query()
            ->where("{$dealsTable}.owner_id", $quota->user_id);

        if ($quota->pipeline_id !== null) {
            $dealsQuery->where("{$dealsTable}.pipeline_id", $quota->pipeline_id);
        }

        if ($teamId !== null) {
            $dealsQuery->where("{$dealsTable}.team_id", $teamId);
        }

        // Won deals closed within the quota period
        $wonAmount = (float) (clone $dealsQuery)
            ->where("{$dealsTable}.status", 'won')
            ->whereBetween("{$dealsTable}.closed_at", [
                $quota->period_start->startOfDay(),
                $quota->period_end->endOfDay(),
            ])
            ->sum("{$dealsTable}.amount");

        // Open pipeline deals expected to close within the quota period
        $openPipelineAmount = (float) (clone $dealsQuery)
            ->where("{$dealsTable}.status", 'open')
            ->whereBetween("{$dealsTable}.expected_close_date", [
                $quota->period_start,
                $quota->period_end,
            ])
            ->sum("{$dealsTable}.amount");

        $target = (float) $quota->target_amount;
        $attainmentPercent = $target > 0 ? round(($wonAmount / $target) * 100, 1) : 0.0;
        $gap = max(0.0, round($target - $wonAmount, 2));
        $coverageRatio = $target > 0 ? round(($wonAmount + $openPipelineAmount) / $target, 2) : 0.0;

        return [
            'target_amount' => round($target, 2),
            'won_amount' => round($wonAmount, 2),
            'attainment_percent' => $attainmentPercent,
            'gap_to_target' => $gap,
            'open_pipeline_amount' => round($openPipelineAmount, 2),
            'coverage_ratio' => $coverageRatio,
        ];
    }
}
