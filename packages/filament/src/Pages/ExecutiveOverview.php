<?php

declare(strict_types=1);

namespace Odden\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Odden\Core\Enums\CustomerHealthStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Filament\Pages\Concerns\AuthorizesPageAccess;
use Odden\Filament\Resources\CampaignResource;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Resources\SalesQuotaResource;
use Odden\Filament\Resources\TicketResource;
use Odden\Marketing\Actions\GetCampaignAttributionAction;
use Odden\Marketing\Models\Campaign;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\SalesQuota;
use Odden\Service\Models\Ticket;
use UnitEnum;

class ExecutiveOverview extends Page
{
    use AuthorizesPageAccess;

    protected static UnitEnum|string|null $navigationGroup = 'Executive';

    protected static ?int $navigationSort = -10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::PresentationChartLine;

    protected static ?string $navigationLabel = 'Executive Overview';

    protected static ?string $title = 'Executive RevOps Command Center';

    protected string $view = 'odden-filament::pages.executive-overview';

    public string $timeframe = 'quarter';

    /**
     * @return list<class-string<\Filament\Resources\Resource>>
     */
    protected static function getAuthorizationResources(): array
    {
        return [
            ContactResource::class,
            CompanyResource::class,
            DealResource::class,
            SalesQuotaResource::class,
            CampaignResource::class,
            TicketResource::class,
        ];
    }

    public function setTimeframe(string $timeframe): void
    {
        $this->timeframe = $timeframe;
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function getDateRange(): array
    {
        return match ($this->timeframe) {
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            'all' => [now()->subYears(5), now()->addYear()],
            default => [now()->startOfQuarter(), now()->endOfQuarter()],
        };
    }

    // ==========================================
    // REVENUE & PIPELINE FLYWHEEL METRICS
    // ==========================================

    public function getActivePipelineProperty(): float
    {
        if (! class_exists(Deal::class)) {
            return 0.0;
        }

        return (float) Deal::query()
            ->where('status', 'open')
            ->sum('amount');
    }

    public function getWeightedForecastProperty(): float
    {
        if (! class_exists(Deal::class)) {
            return 0.0;
        }

        /** @var Collection<int, Deal> $deals */
        $deals = Deal::query()
            ->where('status', 'open')
            ->with('stage')
            ->get();

        $weighted = 0.0;
        foreach ($deals as $deal) {
            $prob = $deal->stage->probability;
            $weighted += ((float) $deal->amount * ((int) $prob / 100));
        }

        return round($weighted, 2);
    }

    public function getClosedWonRevenueProperty(): float
    {
        if (! class_exists(Deal::class)) {
            return 0.0;
        }

        [$start, $end] = $this->getDateRange();

        return (float) Deal::query()
            ->where('status', 'won')
            ->whereBetween('closed_at', [$start, $end])
            ->sum('amount');
    }

    public function getTotalRevenueQuotaProperty(): float
    {
        if (! class_exists(SalesQuota::class)) {
            return 0.0;
        }

        [$start, $end] = $this->getDateRange();

        $sum = (float) SalesQuota::query()
            ->where(function (Builder $q) use ($start, $end): void {
                $q->whereBetween('period_start', [$start, $end])
                    ->orWhereBetween('period_end', [$start, $end]);
            })
            ->sum('target_amount');

        return $sum > 0 ? $sum : 100000.0; // Sensible target benchmark if unconfigured
    }

    public function getQuotaAttainmentRateProperty(): float
    {
        $quota = $this->getTotalRevenueQuotaProperty();
        if ($quota <= 0) {
            return 0.0;
        }

        $won = $this->getClosedWonRevenueProperty();

        return round(($won / $quota) * 100, 1);
    }

    public function getPipelineCoverageRatioProperty(): float
    {
        $quota = $this->getTotalRevenueQuotaProperty();
        if ($quota <= 0) {
            return 0.0;
        }

        return round($this->getActivePipelineProperty() / $quota, 2);
    }

    public function getWinRateProperty(): float
    {
        if (! class_exists(Deal::class)) {
            return 0.0;
        }

        [$start, $end] = $this->getDateRange();

        $wonCount = Deal::query()
            ->where('status', 'won')
            ->whereBetween('closed_at', [$start, $end])
            ->count();

        $lostCount = Deal::query()
            ->where('status', 'lost')
            ->whereBetween('closed_at', [$start, $end])
            ->count();

        $totalClosed = $wonCount + $lostCount;
        if ($totalClosed === 0) {
            return 0.0;
        }

        return round(($wonCount / $totalClosed) * 100, 1);
    }

    public function getAvgDealSizeProperty(): float
    {
        if (! class_exists(Deal::class)) {
            return 0.0;
        }

        [$start, $end] = $this->getDateRange();

        return round((float) (Deal::query()
            ->where('status', 'won')
            ->whereBetween('closed_at', [$start, $end])
            ->avg('amount') ?? 0), 2);
    }

    // ==========================================
    // FLYWHEEL CONVERSION FUNNEL
    // ==========================================

    /**
     * @return array{
     *     leads: int,
     *     mqls: int,
     *     sqls: int,
     *     opportunities: int,
     *     customers: int,
     *     lead_to_mql_rate: float,
     *     mql_to_sql_rate: float,
     *     sql_to_opp_rate: float,
     *     opp_to_cust_rate: float
     * }
     */
    public function getFlywheelFunnelProperty(): array
    {
        $totalContacts = Contact::query()->count();
        $mqls = Contact::query()->where('lifecycle_stage', LifecycleStage::MarketingQualifiedLead->value)->count();
        $sqls = Contact::query()->where('lifecycle_stage', LifecycleStage::SalesQualifiedLead->value)->count();
        $customers = Contact::query()->where('lifecycle_stage', LifecycleStage::Customer->value)->count();

        $opps = class_exists(Deal::class) ? Deal::query()->count() : 0;

        $leads = max(1, $totalContacts);

        return [
            'leads' => $totalContacts,
            'mqls' => $mqls,
            'sqls' => $sqls,
            'opportunities' => $opps,
            'customers' => $customers,
            'lead_to_mql_rate' => round(($mqls / $leads) * 100, 1),
            'mql_to_sql_rate' => $mqls > 0 ? round(($sqls / $mqls) * 100, 1) : 0.0,
            'sql_to_opp_rate' => $sqls > 0 ? round(($opps / $sqls) * 100, 1) : 0.0,
            'opp_to_cust_rate' => $opps > 0 ? round(($customers / $opps) * 100, 1) : 0.0,
        ];
    }

    // ==========================================
    // CUSTOMER ACCOUNT HEALTH (RETENTION)
    // ==========================================

    /**
     * @return array{
     *     total: int,
     *     healthy: int,
     *     neutral: int,
     *     at_risk: int,
     *     avg_score: float,
     *     healthy_pct: float,
     *     neutral_pct: float,
     *     at_risk_pct: float
     * }
     */
    public function getCustomerHealthMetricsProperty(): array
    {
        $total = Company::query()->count();
        if ($total === 0) {
            return [
                'total' => 0,
                'healthy' => 0,
                'neutral' => 0,
                'at_risk' => 0,
                'avg_score' => 70.0,
                'healthy_pct' => 100.0,
                'neutral_pct' => 0.0,
                'at_risk_pct' => 0.0,
            ];
        }

        $healthy = Company::query()->where('health_status', CustomerHealthStatus::Healthy->value)->count();
        $neutral = Company::query()->where('health_status', CustomerHealthStatus::Neutral->value)->count();
        $atRisk = Company::query()->where('health_status', CustomerHealthStatus::AtRisk->value)->count();
        $avgScore = round((float) (Company::query()->avg('health_score') ?? 70), 1);

        return [
            'total' => $total,
            'healthy' => $healthy,
            'neutral' => $neutral,
            'at_risk' => $atRisk,
            'avg_score' => $avgScore,
            'healthy_pct' => round(($healthy / $total) * 100, 1),
            'neutral_pct' => round(($neutral / $total) * 100, 1),
            'at_risk_pct' => round(($atRisk / $total) * 100, 1),
        ];
    }

    // ==========================================
    // CROSS-HUB STRATEGIC HEALTH
    // ==========================================

    /**
     * @return array{
     *     campaigns_count: int,
     *     total_delivered: int,
     *     avg_open_rate: float,
     *     avg_click_rate: float
     * }
     */
    public function getMarketingKpisProperty(): array
    {
        if (! class_exists(Campaign::class)) {
            return ['campaigns_count' => 0, 'total_delivered' => 0, 'avg_open_rate' => 0.0, 'avg_click_rate' => 0.0];
        }

        $campaignsCount = Campaign::query()->where('status', 'sent')->count();
        $delivered = (int) Campaign::query()->sum('delivered_count');
        $uniqueOpens = (int) Campaign::query()->sum('unique_opens_count');
        $uniqueClicks = (int) Campaign::query()->sum('unique_clicks_count');

        $openRate = $delivered > 0 ? round(($uniqueOpens / $delivered) * 100, 1) : 0.0;
        $clickRate = $delivered > 0 ? round(($uniqueClicks / $delivered) * 100, 1) : 0.0;

        return [
            'campaigns_count' => $campaignsCount,
            'total_delivered' => $delivered,
            'avg_open_rate' => $openRate,
            'avg_click_rate' => $clickRate,
        ];
    }

    /**
     * @return array{
     *     open_tickets: int,
     *     urgent_open: int,
     *     sla_breaches: int,
     *     avg_csat: float,
     *     sla_compliance_rate: float
     * }
     */
    public function getServiceKpisProperty(): array
    {
        if (! class_exists(Ticket::class)) {
            return ['open_tickets' => 0, 'urgent_open' => 0, 'sla_breaches' => 0, 'avg_csat' => 5.0, 'sla_compliance_rate' => 100.0];
        }

        $openTickets = Ticket::query()->whereNotIn('status', ['resolved', 'closed'])->count();
        $urgentOpen = Ticket::query()->whereNotIn('status', ['resolved', 'closed'])->whereIn('priority', ['urgent', 'high'])->count();
        $slaBreaches = Ticket::query()->where('is_sla_response_breached', true)->orWhere('is_sla_resolution_breached', true)->count();
        $avgCsat = round((float) (Ticket::query()->whereNotNull('csat_rating')->avg('csat_rating') ?? 4.8), 2);

        $totalEvaluated = max(1, Ticket::query()->count());
        $complianceRate = round((($totalEvaluated - $slaBreaches) / $totalEvaluated) * 100, 1);

        return [
            'open_tickets' => $openTickets,
            'urgent_open' => $urgentOpen,
            'sla_breaches' => $slaBreaches,
            'avg_csat' => $avgCsat,
            'sla_compliance_rate' => max(0.0, $complianceRate),
        ];
    }

    // ==========================================
    // EXECUTIVE ATTENTION RADAR
    // ==========================================

    /**
     * Top high-value deals closing soon.
     *
     * @return Collection<int, Deal>
     */
    public function getHighValueDealsProperty(): Collection
    {
        if (! class_exists(Deal::class)) {
            return new Collection;
        }

        return Deal::query()
            ->where('status', 'open')
            ->where('amount', '>=', 10000)
            ->with('stage')
            ->orderBy('amount', 'desc')
            ->limit(5)
            ->get();
    }

    /**
     * Strategic accounts currently at risk.
     *
     * @return Collection<int, Company>
     */
    public function getAtRiskStrategicAccountsProperty(): Collection
    {
        return Company::query()
            ->where('health_status', CustomerHealthStatus::AtRisk->value)
            ->orderBy('health_score', 'asc')
            ->limit(5)
            ->get();
    }

    /**
     * Stalled and rotting pipeline deals requiring intervention.
     *
     * @return Collection<int, Deal>
     */
    public function getStalledRottingDealsProperty(): Collection
    {
        if (! class_exists(Deal::class)) {
            return new Collection;
        }

        /** @var Collection<int, Deal> $openDeals */
        $openDeals = Deal::query()
            ->where('status', 'open')
            ->with(['stage', 'owner'])
            ->orderBy('amount', 'desc')
            ->get();

        return $openDeals->filter(function (Deal $deal): bool {
            if ($deal->isRotten()) {
                return true;
            }

            return $deal->daysInCurrentStage() >= 21;
        })->take(5);
    }

    /**
     * Sales AE rep quota attainment leaderboard.
     *
     * @return array<int, array{
     *     user_id: int,
     *     name: string,
     *     email: string,
     *     quota: float,
     *     won: float,
     *     attainment: float,
     *     pipeline: float,
     *     deals_count: int
     * }>
     */
    public function getRepLeaderboardProperty(): array
    {
        if (! class_exists(Deal::class)) {
            return [];
        }

        [$start, $end] = $this->getDateRange();

        $quotasByUser = [];
        if (class_exists(SalesQuota::class)) {
            $quotas = SalesQuota::query()
                ->where(function (Builder $q) use ($start, $end): void {
                    $q->whereBetween('period_start', [$start, $end])
                        ->orWhereBetween('period_end', [$start, $end]);
                })
                ->get();

            foreach ($quotas as $q) {
                $quotasByUser[$q->user_id] = (float) $q->target_amount;
            }
        }

        $wonDeals = Deal::query()
            ->where('status', 'won')
            ->whereBetween('closed_at', [$start, $end])
            ->whereNotNull('owner_id')
            ->get(['owner_id', 'amount']);

        $wonByUser = [];
        foreach ($wonDeals as $d) {
            $ownerId = (int) $d->owner_id;
            $wonByUser[$ownerId] = ($wonByUser[$ownerId] ?? 0.0) + (float) $d->amount;
        }

        $openDeals = Deal::query()
            ->where('status', 'open')
            ->whereNotNull('owner_id')
            ->get(['owner_id', 'amount']);

        $pipelineByUser = [];
        $dealCountByUser = [];
        foreach ($openDeals as $d) {
            $ownerId = (int) $d->owner_id;
            $pipelineByUser[$ownerId] = ($pipelineByUser[$ownerId] ?? 0.0) + (float) $d->amount;
            $dealCountByUser[$ownerId] = ($dealCountByUser[$ownerId] ?? 0) + 1;
        }

        $allUserIds = array_unique(array_merge(
            array_keys($quotasByUser),
            array_keys($wonByUser),
            array_keys($pipelineByUser)
        ));

        if (empty($allUserIds)) {
            return [];
        }

        $users = UserModel::query()->whereIn('id', $allUserIds)->get()->keyBy('id');

        $leaderboard = [];
        foreach ($allUserIds as $userId) {
            $user = $users->get($userId);
            if (! $user) {
                continue;
            }

            $quota = $quotasByUser[$userId] ?? 50000.0;
            $won = $wonByUser[$userId] ?? 0.0;
            $attainment = $quota > 0 ? round(($won / $quota) * 100, 1) : 0.0;

            $leaderboard[] = [
                'user_id' => (int) $userId,
                'name' => (string) ($user->getAttribute('name') ?? 'AE #'.$userId),
                'email' => (string) ($user->getAttribute('email') ?? ''),
                'quota' => $quota,
                'won' => $won,
                'attainment' => $attainment,
                'pipeline' => $pipelineByUser[$userId] ?? 0.0,
                'deals_count' => $dealCountByUser[$userId] ?? 0,
            ];
        }

        usort($leaderboard, fn (array $a, array $b): int => $b['attainment'] <=> $a['attainment']);

        return array_slice($leaderboard, 0, 5);
    }

    /**
     * Top marketing campaigns driving pipeline and closed revenue.
     *
     * @return array<int, array{
     *     name: string,
     *     status: string,
     *     budget: float,
     *     actual_cost: float,
     *     won_revenue: float,
     *     pipeline_value: float,
     *     roi: float
     * }>
     */
    public function getTopRevenueCampaignsProperty(): array
    {
        if (! class_exists(Campaign::class) || ! class_exists(GetCampaignAttributionAction::class)) {
            return [];
        }

        $campaigns = Campaign::query()->where('status', 'sent')->limit(5)->get();
        $action = app(GetCampaignAttributionAction::class);

        $results = [];
        foreach ($campaigns as $campaign) {
            $attr = $action->execute($campaign);

            $results[] = [
                'name' => $campaign->name,
                'status' => $campaign->status->value,
                'budget' => (float) ($campaign->budget ?? 0),
                'actual_cost' => (float) ($campaign->actual_cost ?? 0),
                'won_revenue' => $attr['won_revenue'],
                'pipeline_value' => $attr['pipeline_value'],
                'roi' => $attr['roi_percentage'],
            ];
        }

        usort($results, fn (array $a, array $b): int => $b['won_revenue'] <=> $a['won_revenue']);

        return $results;
    }
}
