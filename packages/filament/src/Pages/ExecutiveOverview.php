<?php

declare(strict_types=1);

namespace Odden\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Odden\Core\Encryption\EncryptedAmounts;
use Odden\Core\Enums\CustomerHealthStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\Filament\Pages\Concerns\AuthorizesPageAccess;
use Odden\Filament\Resources\CompanyResource;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Resources\SalesQuotaResource;
use Odden\Filament\Support\Modules;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\SalesQuota;
use Odden\Sales\Support\Money;
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
            // Sales turned off on this panel: its permissions do not decide who sees the page.
            ...(Modules::enabledOnCurrentPanel('sales') ? [DealResource::class, SalesQuotaResource::class] : []),
            ...Modules::authorizationResources(),
        ];
    }

    /**
     * The summary cards that separately installed packages (Marketing, Service) add to the cross-hub row.
     *
     * @return list<array{view: string, data: array<string, mixed>, position: 'before'|'after'}>
     */
    public function getExecutiveCardsProperty(): array
    {
        return Modules::executiveCards();
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

    /**
     * The one currency the revenue figures are reported in (amounts are never added across currencies).
     */
    public function getReportingCurrencyProperty(): string
    {
        if (! Modules::salesEnabled()) {
            return Money::defaultCurrency();
        }

        return Money::reportingCurrency(OddenAuthorization::scoped(Deal::class));
    }

    /**
     * The prefix for the revenue figures, such as "$" or "CHF ".
     */
    public function getCurrencyPrefixProperty(): string
    {
        return Money::prefix($this->getReportingCurrencyProperty());
    }

    /**
     * Open and won totals of deals in currencies other than the reporting currency.
     *
     * @return array<string, string>
     */
    public function getOtherCurrencyTotalsProperty(): array
    {
        if (! Modules::salesEnabled()) {
            return [];
        }

        $reporting = $this->getReportingCurrencyProperty();
        $totals = [];

        foreach (OddenAuthorization::scoped(Deal::class)->where('currency', '!=', $reporting)->whereIn('status', ['open', 'won'])->get(['currency', 'amount', 'amount_encrypted', 'status']) as $deal) {
            $code = strtoupper((string) $deal->currency);
            $totals[$code] = ($totals[$code] ?? 0.0) + (float) $deal->amount;
        }

        ksort($totals);

        $formatted = [];
        foreach ($totals as $code => $amount) {
            $formatted[$code] = Money::format($amount, $code);
        }

        return $formatted;
    }

    /**
     * Deals in the reporting currency.
     *
     * @return Builder<Deal>
     */
    protected function reportingDeals(): Builder
    {
        return OddenAuthorization::scoped(Deal::class)->where('currency', $this->getReportingCurrencyProperty());
    }

    public function getActivePipelineProperty(): float
    {
        if (! Modules::salesEnabled()) {
            return 0.0;
        }

        return app(EncryptedAmounts::class)->sum($this->reportingDeals()->where('status', 'open'), 'amount');
    }

    public function getWeightedForecastProperty(): float
    {
        if (! Modules::salesEnabled()) {
            return 0.0;
        }

        /** @var Collection<int, Deal> $deals */
        $deals = $this->reportingDeals()
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
        if (! Modules::salesEnabled()) {
            return 0.0;
        }

        [$start, $end] = $this->getDateRange();

        return app(EncryptedAmounts::class)->sum($this->reportingDeals()
            ->where('status', 'won')
            ->whereBetween('closed_at', [$start, $end]), 'amount');
    }

    /**
     * Quotas in the reporting currency whose period overlaps the selected range.
     *
     * @return Builder<SalesQuota>
     */
    protected function quotasInRange(): Builder
    {
        [$start, $end] = $this->getDateRange();

        return OddenAuthorization::scoped(SalesQuota::class)
            ->where('currency', $this->getReportingCurrencyProperty())
            ->whereDate('period_start', '<=', $end)
            ->whereDate('period_end', '>=', $start);
    }

    /**
     * The summed targets of the quotas in the selected range, or null when no quota is set for it.
     */
    public function getTotalRevenueQuotaProperty(): ?float
    {
        if (! Modules::salesEnabled()) {
            return null;
        }

        $sum = app(EncryptedAmounts::class)->sum($this->quotasInRange(), 'target_amount');

        return $sum > 0 ? $sum : null;
    }

    public function getQuotaAttainmentRateProperty(): ?float
    {
        $quota = $this->getTotalRevenueQuotaProperty();
        if ($quota === null) {
            return null;
        }

        $won = $this->getClosedWonRevenueProperty();

        return round(($won / $quota) * 100, 1);
    }

    public function getPipelineCoverageRatioProperty(): ?float
    {
        $quota = $this->getTotalRevenueQuotaProperty();
        if ($quota === null) {
            return null;
        }

        return round($this->getActivePipelineProperty() / $quota, 2);
    }

    public function getWinRateProperty(): float
    {
        if (! Modules::salesEnabled()) {
            return 0.0;
        }

        [$start, $end] = $this->getDateRange();

        $wonCount = OddenAuthorization::scoped(Deal::class)
            ->where('status', 'won')
            ->whereBetween('closed_at', [$start, $end])
            ->count();

        $lostCount = OddenAuthorization::scoped(Deal::class)
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
        if (! Modules::salesEnabled()) {
            return 0.0;
        }

        [$start, $end] = $this->getDateRange();

        return round(app(EncryptedAmounts::class)->average($this->reportingDeals()
            ->where('status', 'won')
            ->whereBetween('closed_at', [$start, $end]), 'amount'), 2);
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
        $totalContacts = OddenAuthorization::scoped(Contact::class)->count();
        $mqls = OddenAuthorization::scoped(Contact::class)->where('lifecycle_stage', LifecycleStage::MarketingQualifiedLead->value)->count();
        $sqls = OddenAuthorization::scoped(Contact::class)->where('lifecycle_stage', LifecycleStage::SalesQualifiedLead->value)->count();
        $customers = OddenAuthorization::scoped(Contact::class)->where('lifecycle_stage', LifecycleStage::Customer->value)->count();

        $opps = Modules::salesEnabled() ? OddenAuthorization::scoped(Deal::class)->count() : 0;

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
        $total = OddenAuthorization::scoped(Company::class)->count();
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

        $healthy = OddenAuthorization::scoped(Company::class)->where('health_status', CustomerHealthStatus::Healthy->value)->count();
        $neutral = OddenAuthorization::scoped(Company::class)->where('health_status', CustomerHealthStatus::Neutral->value)->count();
        $atRisk = OddenAuthorization::scoped(Company::class)->where('health_status', CustomerHealthStatus::AtRisk->value)->count();
        $avgScore = round((float) (OddenAuthorization::scoped(Company::class)->avg('health_score') ?? 70), 1);

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
        if (! Modules::salesEnabled()) {
            return new Collection;
        }

        return app(EncryptedAmounts::class)
            ->top($this->reportingDeals()->where('status', 'open')->with('stage'), 'amount', limit: 5, atLeast: 10000)
            ->get();
    }

    /**
     * Strategic accounts currently at risk.
     *
     * @return Collection<int, Company>
     */
    public function getAtRiskStrategicAccountsProperty(): Collection
    {
        return OddenAuthorization::scoped(Company::class)
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
        if (! Modules::salesEnabled()) {
            return new Collection;
        }

        /** @var Collection<int, Deal> $openDeals */
        $openDeals = OddenAuthorization::scoped(Deal::class)
            ->where('status', 'open')
            // isRotten() and daysInCurrentStage() read the stage history of every deal.
            ->with(['stage', 'owner', 'stageHistory'])
            ->get()
            ->sortByDesc(fn (Deal $deal): float => (float) $deal->amount)
            ->values();

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
     *     quota: float|null,
     *     won: float,
     *     attainment: float|null,
     *     pipeline: float,
     *     deals_count: int
     * }>
     */
    public function getRepLeaderboardProperty(): array
    {
        if (! Modules::salesEnabled()) {
            return [];
        }

        [$start, $end] = $this->getDateRange();

        // Every quota a rep has in the range counts: three monthly quotas make up a quarter.
        $quotasByUser = [];
        foreach ($this->quotasInRange()->get(['user_id', 'target_amount', 'target_amount_encrypted']) as $q) {
            $quotasByUser[$q->user_id] = ($quotasByUser[$q->user_id] ?? 0.0) + (float) $q->target_amount;
        }

        $wonDeals = $this->reportingDeals()
            ->where('status', 'won')
            ->whereBetween('closed_at', [$start, $end])
            ->whereNotNull('owner_id')
            ->get(['owner_id', 'amount', 'amount_encrypted']);

        $wonByUser = [];
        foreach ($wonDeals as $d) {
            $ownerId = (int) $d->owner_id;
            $wonByUser[$ownerId] = ($wonByUser[$ownerId] ?? 0.0) + (float) $d->amount;
        }

        $openDeals = $this->reportingDeals()
            ->where('status', 'open')
            ->whereNotNull('owner_id')
            ->get(['owner_id', 'amount', 'amount_encrypted']);

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

            // No quota set: there is nothing to measure attainment against.
            $quota = isset($quotasByUser[$userId]) && $quotasByUser[$userId] > 0 ? $quotasByUser[$userId] : null;
            $won = $wonByUser[$userId] ?? 0.0;
            $attainment = $quota !== null ? round(($won / $quota) * 100, 1) : null;

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

        // Reps with a quota first, by attainment; then reps without one, by revenue won.
        usort($leaderboard, fn (array $a, array $b): int => [$b['attainment'] !== null, $b['attainment'] ?? 0.0, $b['won']]
            <=> [$a['attainment'] !== null, $a['attainment'] ?? 0.0, $a['won']]);

        return array_slice($leaderboard, 0, 5);
    }
}
