<x-filament-panels::page>
    <style>
        .exec-container {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            color: #0f172a;
            font-family: inherit;
        }

        :where(.dark, .dark *) .exec-container {
            color: #f8fafc;
        }

        /* Top Header Card */
        .exec-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
        }

        :where(.dark, .dark *) .exec-header {
            background: #111827;
            border-color: #1f2937;
        }

        @media (min-width: 640px) {
            .exec-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .exec-title {
            font-size: 1.25rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #0f172a;
            letter-spacing: -0.02em;
        }

        :where(.dark, .dark *) .exec-title {
            color: #ffffff;
        }

        .exec-subtitle {
            font-size: 0.8125rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        :where(.dark, .dark *) .exec-subtitle {
            color: #94a3b8;
        }

        .exec-header-actions {
            display: flex;
            align-items: center;
            gap: 0.625rem;
            flex-wrap: wrap;
        }

        .exec-timeframe-switcher {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            background: #f1f5f9;
            padding: 0.25rem;
            border-radius: 0.5rem;
            border: 1px solid #e2e8f0;
        }

        :where(.dark, .dark *) .exec-timeframe-switcher {
            background: #1f2937;
            border-color: #374151;
        }

        .exec-timeframe-btn {
            padding: 0.375rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 0.375rem;
            background: transparent;
            color: #64748b;
            border: none;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        :where(.dark, .dark *) .exec-timeframe-btn {
            color: #9ca3af;
        }

        .exec-timeframe-btn:hover {
            color: #0f172a;
        }

        :where(.dark, .dark *) .exec-timeframe-btn:hover {
            color: #ffffff;
        }

        .exec-timeframe-btn.active {
            background: #ffffff;
            color: #f97316;
            font-weight: 700;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }

        :where(.dark, .dark *) .exec-timeframe-btn.active {
            background: #111827;
            color: #fb923c;
        }

        .exec-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.4375rem 0.875rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 0.5rem;
            background: #ffffff;
            color: #334155;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.04);
            transition: all 0.15s ease;
        }

        :where(.dark, .dark *) .exec-btn {
            background: #1f2937;
            color: #e2e8f0;
            border-color: #374151;
        }

        .exec-btn:hover {
            background: #f8fafc;
        }

        :where(.dark, .dark *) .exec-btn:hover {
            background: #374151;
        }

        /* KPI Grid */
        .exec-kpi-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1rem;
        }

        @media (min-width: 640px) {
            .exec-kpi-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (min-width: 1024px) {
            .exec-kpi-grid {
                grid-template-columns: repeat(4, minmax(0, 1fr));
            }
        }

        /* Cards */
        .exec-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        :where(.dark, .dark *) .exec-card {
            background: #111827;
            border-color: #1f2937;
        }

        .exec-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.5rem;
        }

        .exec-card-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
        }

        :where(.dark, .dark *) .exec-card-label {
            color: #94a3b8;
        }

        .exec-card-value {
            font-size: 1.625rem;
            font-weight: 900;
            line-height: 1.2;
            color: #0f172a;
        }

        :where(.dark, .dark *) .exec-card-value {
            color: #ffffff;
        }

        .exec-card-meta {
            margin-top: 0.5rem;
            font-size: 0.75rem;
            color: #64748b;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        :where(.dark, .dark *) .exec-card-meta {
            color: #94a3b8;
        }

        /* Progress bars */
        .exec-progress-track {
            width: 100%;
            height: 0.375rem;
            background: #f1f5f9;
            border-radius: 9999px;
            overflow: hidden;
            margin-top: 0.375rem;
        }

        :where(.dark, .dark *) .exec-progress-track {
            background: #374151;
        }

        .exec-progress-bar {
            height: 100%;
            border-radius: 9999px;
            transition: width 0.3s ease;
        }

        /* Panels (Full Width / Multi-column) */
        .exec-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
        }

        :where(.dark, .dark *) .exec-panel {
            background: #111827;
            border-color: #1f2937;
        }

        .exec-panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .exec-panel-title {
            font-size: 0.9375rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #0f172a;
        }

        :where(.dark, .dark *) .exec-panel-title {
            color: #ffffff;
        }

        /* Funnel Grid */
        .exec-funnel-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 0.75rem;
        }

        @media (min-width: 768px) {
            .exec-funnel-grid {
                grid-template-columns: repeat(5, minmax(0, 1fr));
            }
        }

        .exec-funnel-step {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 0.625rem;
            padding: 1rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 5.5rem;
        }

        :where(.dark, .dark *) .exec-funnel-step {
            background: #1e293b;
            border-color: #334155;
        }

        .exec-funnel-step.step-won {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        :where(.dark, .dark *) .exec-funnel-step.step-won {
            background: #064e3b;
            border-color: #047857;
        }

        /* 2-Column and 3-Column Layouts */
        .exec-grid-2 {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1rem;
        }

        @media (min-width: 1024px) {
            .exec-grid-2 {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .exec-grid-3 {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .exec-grid-3 {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        /* Rows & Lists */
        .exec-list-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.625rem 0;
            border-bottom: 1px solid #f1f5f9;
            font-size: 0.75rem;
        }

        :where(.dark, .dark *) .exec-list-row {
            border-bottom-color: #1f2937;
        }

        .exec-list-row:last-child {
            border-bottom: none;
        }

        .exec-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.125rem 0.375rem;
            font-size: 0.625rem;
            font-weight: 700;
            border-radius: 0.25rem;
            text-transform: uppercase;
        }

        .exec-badge-amber {
            background: #fffbeb;
            color: #d97706;
            border: 1px solid #fde68a;
        }

        :where(.dark, .dark *) .exec-badge-amber {
            background: #451a03;
            color: #fbbf24;
            border-color: #78350f;
        }

        .exec-badge-rose {
            background: #fff1f2;
            color: #e11d48;
            border: 1px solid #fecdd3;
        }

        :where(.dark, .dark *) .exec-badge-rose {
            background: #4c0519;
            color: #fb7185;
            border-color: #881337;
        }

        /* SVG Constraints */
        .exec-container svg {
            display: inline-block;
            vertical-align: middle;
            flex-shrink: 0;
        }

        @media print {
            .fi-topbar, .fi-sidebar, .hub-no-print {
                display: none !important;
            }
            .fi-main {
                padding: 0 !important;
            }
            body {
                background: white !important;
                color: black !important;
            }
        }
    </style>

    <div class="exec-container">
        {{-- Top Header & Timeframe Switcher --}}
        <div class="exec-header">
            <div>
                <h2 class="exec-title">
                    <x-filament::icon icon="heroicon-m-presentation-chart-line" class="text-primary-500" style="width: 1.5rem; height: 1.5rem; color: #f97316;" />
                    RevOps Executive Dashboard
                </h2>
                <p class="exec-subtitle">
                    Unified cross-functional performance across Marketing acquisition, Sales pipeline velocity, and Account Retention.
                </p>
            </div>

            <div class="exec-header-actions hub-no-print">
                <div class="exec-timeframe-switcher">
                    <button
                        type="button"
                        wire:click="setTimeframe('month')"
                        class="exec-timeframe-btn {{ $timeframe === 'month' ? 'active' : '' }}">
                        Month
                    </button>
                    <button
                        type="button"
                        wire:click="setTimeframe('quarter')"
                        class="exec-timeframe-btn {{ $timeframe === 'quarter' ? 'active' : '' }}">
                        Quarter
                    </button>
                    <button
                        type="button"
                        wire:click="setTimeframe('year')"
                        class="exec-timeframe-btn {{ $timeframe === 'year' ? 'active' : '' }}">
                        YTD
                    </button>
                    <button
                        type="button"
                        wire:click="setTimeframe('all')"
                        class="exec-timeframe-btn {{ $timeframe === 'all' ? 'active' : '' }}">
                        All Time
                    </button>
                </div>

                <button
                    type="button"
                    onclick="window.print()"
                    class="exec-btn">
                    <x-filament::icon icon="heroicon-m-printer" style="width: 1rem; height: 1rem; color: #64748b;" />
                    Print Brief
                </button>
            </div>
        </div>

        {{-- Top KPI Row --}}
        <div class="exec-kpi-grid">
            {{-- KPI 1: Active Pipeline --}}
            <div class="exec-card">
                <div>
                    <div class="exec-card-header">
                        <span class="exec-card-label">Active Pipeline</span>
                        <x-filament::icon icon="heroicon-m-funnel" style="width: 1.25rem; height: 1.25rem; color: #6366f1;" />
                    </div>
                    <div class="exec-card-value">
                        ${{ number_format($this->activePipeline, 2) }}
                    </div>
                </div>
                <div>
                    <div class="exec-card-meta">
                        <span>Weighted Forecast:</span>
                        <strong style="color: #0f172a;">${{ number_format($this->weightedForecast, 2) }}</strong>
                    </div>
                    <div style="margin-top: 0.25rem; font-size: 0.75rem; color: #6366f1; font-weight: 600;">
                        Coverage: {{ $this->pipelineCoverageRatio }}x quota
                    </div>
                </div>
            </div>

            {{-- KPI 2: Closed-Won Revenue & Quota Attainment --}}
            <div class="exec-card">
                <div>
                    <div class="exec-card-header">
                        <span class="exec-card-label">Closed-Won Revenue</span>
                        <x-filament::icon icon="heroicon-m-banknotes" style="width: 1.25rem; height: 1.25rem; color: #10b981;" />
                    </div>
                    <div class="exec-card-value">
                        ${{ number_format($this->closedWonRevenue, 2) }}
                    </div>
                </div>
                <div>
                    <div class="exec-card-meta">
                        <span>Attainment:</span>
                        <strong style="color: #10b981;">{{ $this->quotaAttainmentRate }}%</strong>
                    </div>
                    <div class="exec-progress-track">
                        <div class="exec-progress-bar" style="background: #10b981; width: {{ min(100, $this->quotaAttainmentRate) }}%;"></div>
                    </div>
                    <div style="margin-top: 0.25rem; font-size: 0.6875rem; color: #64748b;">
                        Target Quota: ${{ number_format($this->totalRevenueQuota, 0) }}
                    </div>
                </div>
            </div>

            {{-- KPI 3: Sales Win Rate & Deal Size --}}
            <div class="exec-card">
                <div>
                    <div class="exec-card-header">
                        <span class="exec-card-label">Win Rate</span>
                        <x-filament::icon icon="heroicon-m-trophy" style="width: 1.25rem; height: 1.25rem; color: #f59e0b;" />
                    </div>
                    <div class="exec-card-value">
                        {{ $this->winRate }}%
                    </div>
                </div>
                <div>
                    <div class="exec-card-meta">
                        <span>Average Deal Size:</span>
                        <strong style="color: #0f172a;">${{ number_format($this->avgDealSize, 0) }}</strong>
                    </div>
                    <div style="margin-top: 0.25rem; font-size: 0.75rem; color: #f59e0b; font-weight: 600;">
                        Commercial efficiency
                    </div>
                </div>
            </div>

            {{-- KPI 4: Customer Account Health --}}
            @php $health = $this->customerHealthMetrics; @endphp
            <div class="exec-card">
                <div>
                    <div class="exec-card-header">
                        <span class="exec-card-label">Account Health</span>
                        <x-filament::icon icon="heroicon-m-heart" style="width: 1.25rem; height: 1.25rem; color: #f43f5e;" />
                    </div>
                    <div class="exec-card-value">
                        {{ $health['avg_score'] }} <span style="font-size: 0.875rem; font-weight: 600; color: #94a3b8;">/ 100</span>
                    </div>
                </div>
                <div>
                    <div class="exec-card-meta">
                        <span style="color: #10b981; font-weight: 700;">{{ $health['healthy'] }} Healthy</span>
                        <span style="color: #f59e0b; font-weight: 600;">{{ $health['neutral'] }} Neutral</span>
                        <span style="color: #f43f5e; font-weight: 700;">{{ $health['at_risk'] }} At Risk</span>
                    </div>
                    <div class="exec-progress-track" style="display: flex;">
                        <div style="background: #10b981; height: 100%; width: {{ $health['healthy_pct'] }}%;"></div>
                        <div style="background: #f59e0b; height: 100%; width: {{ $health['neutral_pct'] }}%;"></div>
                        <div style="background: #f43f5e; height: 100%; width: {{ $health['at_risk_pct'] }}%;"></div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Flywheel Conversion Funnel --}}
        @php $funnel = $this->flywheelFunnel; @endphp
        <div class="exec-panel">
            <div class="exec-panel-header">
                <div>
                    <h3 class="exec-panel-title">
                        <x-filament::icon icon="heroicon-m-arrow-trending-up" style="width: 1.25rem; height: 1.25rem; color: #f97316;" />
                        Full Flywheel Conversion Funnel
                    </h3>
                    <p class="exec-subtitle">
                        Cross-hub pipeline lifecycle from lead generation to closed-won revenue customer.
                    </p>
                </div>
            </div>

            <div class="exec-funnel-grid">
                {{-- Stage 1: Leads --}}
                <div class="exec-funnel-step">
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase;">1. Leads</div>
                        <div style="font-size: 1.5rem; font-weight: 900; margin-top: 0.25rem;">{{ number_format($funnel['leads']) }}</div>
                    </div>
                    <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.5rem;">Top-of-funnel contacts</div>
                </div>

                {{-- Stage 2: MQLs --}}
                <div class="exec-funnel-step">
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #6366f1; text-transform: uppercase;">2. MQLs</div>
                        <div style="font-size: 1.5rem; font-weight: 900; margin-top: 0.25rem;">{{ number_format($funnel['mqls']) }}</div>
                    </div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #6366f1; margin-top: 0.5rem;">
                        {{ $funnel['lead_to_mql_rate'] }}% from Leads
                    </div>
                </div>

                {{-- Stage 3: SQLs --}}
                <div class="exec-funnel-step">
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #0284c7; text-transform: uppercase;">3. SQLs</div>
                        <div style="font-size: 1.5rem; font-weight: 900; margin-top: 0.25rem;">{{ number_format($funnel['sqls']) }}</div>
                    </div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #0284c7; margin-top: 0.5rem;">
                        {{ $funnel['mql_to_sql_rate'] }}% from MQLs
                    </div>
                </div>

                {{-- Stage 4: Opportunities --}}
                <div class="exec-funnel-step">
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #d97706; text-transform: uppercase;">4. Pipeline Deals</div>
                        <div style="font-size: 1.5rem; font-weight: 900; margin-top: 0.25rem;">{{ number_format($funnel['opportunities']) }}</div>
                    </div>
                    <div style="font-size: 0.75rem; font-weight: 600; color: #d97706; margin-top: 0.5rem;">
                        {{ $funnel['sql_to_opp_rate'] }}% qualified
                    </div>
                </div>

                {{-- Stage 5: Won Customers --}}
                <div class="exec-funnel-step step-won">
                    <div>
                        <div style="font-size: 0.75rem; font-weight: 700; color: #16a34a; text-transform: uppercase;">5. Customers</div>
                        <div style="font-size: 1.5rem; font-weight: 900; margin-top: 0.25rem; color: #16a34a;">{{ number_format($funnel['customers']) }}</div>
                    </div>
                    <div style="font-size: 0.75rem; font-weight: 700; color: #15803d; margin-top: 0.5rem;">
                        {{ $funnel['opp_to_cust_rate'] }}% win conversion
                    </div>
                </div>
            </div>
        </div>

        {{-- Mid Row: Rep Quota Leaderboard & Deal Rot Radar --}}
        <div class="exec-grid-2">
            {{-- Rep Quota Attainment Leaderboard --}}
            <div class="exec-panel">
                <div class="exec-panel-header">
                    <h4 class="exec-panel-title">
                        <x-filament::icon icon="heroicon-m-user-group" style="width: 1rem; height: 1rem; color: #10b981;" />
                        AE Quota Attainment Leaderboard
                    </h4>
                    <span style="font-size: 0.75rem; color: #94a3b8;">Ranked by Goal %</span>
                </div>

                @php $leaderboard = $this->repLeaderboard; @endphp
                @if (empty($leaderboard))
                    <div style="padding: 1.5rem 0; text-align: center; font-size: 0.75rem; color: #64748b;">
                        No sales quota or closed deals recorded for this period.
                    </div>
                @else
                    <div>
                        @foreach ($leaderboard as $rep)
                            <div class="exec-list-row">
                                <div style="min-width: 0; padding-right: 1rem;">
                                    <div style="font-weight: 700; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                        {{ $rep['name'] }}
                                    </div>
                                    <div style="font-size: 0.6875rem; color: #94a3b8;">
                                        {{ $rep['email'] }} • Pipeline: ${{ number_format($rep['pipeline'], 0) }}
                                    </div>
                                </div>
                                <div style="width: 10rem; text-align: right; flex-shrink: 0;">
                                    <div style="display: flex; align-items: center; justify-content: flex-end; gap: 0.375rem; margin-bottom: 0.25rem;">
                                        <strong style="color: {{ $rep['attainment'] >= 100 ? '#10b981' : 'inherit' }};">
                                            ${{ number_format($rep['won'], 0) }}
                                        </strong>
                                        <span style="font-size: 0.625rem; color: #94a3b8;">/ ${{ number_format($rep['quota'], 0) }}</span>
                                        <strong style="font-size: 0.6875rem; color: {{ $rep['attainment'] >= 100 ? '#10b981' : ($rep['attainment'] >= 70 ? '#f59e0b' : '#f43f5e') }};">
                                            ({{ $rep['attainment'] }}%)
                                        </strong>
                                    </div>
                                    <div class="exec-progress-track">
                                        <div class="exec-progress-bar" style="background: {{ $rep['attainment'] >= 100 ? '#10b981' : ($rep['attainment'] >= 70 ? '#f59e0b' : '#f43f5e') }}; width: {{ min(100, $rep['attainment']) }}%;"></div>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Deal Rot & Stalled Pipeline Radar --}}
            <div class="exec-panel">
                <div class="exec-panel-header">
                    <h4 class="exec-panel-title">
                        <x-filament::icon icon="heroicon-m-clock" style="width: 1rem; height: 1rem; color: #f59e0b;" />
                        Stalled and Rotting Deals Radar
                    </h4>
                    <span style="font-size: 0.75rem; color: #94a3b8;">Stage Aging Thresholds</span>
                </div>

                @php $rottingDeals = $this->stalledRottingDeals; @endphp
                @if ($rottingDeals->isEmpty())
                    <div style="padding: 1.5rem 0; text-align: center; font-size: 0.75rem; color: #10b981; font-weight: 600;">
                        ✓ No deals currently stalled or exceeding stage aging limits.
                    </div>
                @else
                    <div>
                        @foreach ($rottingDeals as $deal)
                            <div class="exec-list-row">
                                <div>
                                    <div style="font-weight: 700; display: flex; align-items: center; gap: 0.375rem;">
                                        {{ $deal->name }}
                                        <span class="exec-badge exec-badge-amber">
                                            {{ $deal->daysInCurrentStage() }}d in stage
                                        </span>
                                    </div>
                                    <div style="font-size: 0.6875rem; color: #94a3b8;">
                                        Stage: {{ $deal->stage?->name ?? 'Open' }} • Rep: {{ $deal->owner?->name ?? 'Unassigned' }}
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <strong style="font-size: 0.8125rem;">
                                        ${{ number_format((float) $deal->amount, 0) }}
                                    </strong>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Cross-Hub Strategic Summary (Marketing, Sales, Service) --}}
        <div class="exec-grid-3">
            @foreach ($this->executiveCards as $card)
                @if ($card['position'] === 'before')
                    @include($card['view'], $card['data'])
                @endif
            @endforeach

            {{-- Sales Summary --}}
            <div class="exec-card">
                <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.75rem;">
                    <x-filament::icon icon="heroicon-m-rocket-launch" style="width: 1.25rem; height: 1.25rem; color: #10b981;" />
                    <h4 style="font-size: 0.8125rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em;">Sales Velocity</h4>
                </div>
                <div style="display: flex; flex-direction: column; gap: 0.5rem; font-size: 0.75rem;">
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.375rem; border-bottom: 1px solid #f1f5f9;">
                        <span style="color: #64748b;">Pipeline Coverage:</span>
                        <strong style="color: #10b981;">{{ $this->pipelineCoverageRatio }}x Target</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.375rem; border-bottom: 1px solid #f1f5f9;">
                        <span style="color: #64748b;">Weighted Forecast:</span>
                        <strong>${{ number_format($this->weightedForecast, 0) }}</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between; padding-bottom: 0.375rem; border-bottom: 1px solid #f1f5f9;">
                        <span style="color: #64748b;">Win Rate:</span>
                        <strong>{{ $this->winRate }}%</strong>
                    </div>
                    <div style="display: flex; justify-content: space-between;">
                        <span style="color: #64748b;">Avg Deal Size:</span>
                        <strong>${{ number_format($this->avgDealSize, 0) }}</strong>
                    </div>
                </div>
            </div>

            @foreach ($this->executiveCards as $card)
                @if ($card['position'] === 'after')
                    @include($card['view'], $card['data'])
                @endif
            @endforeach
        </div>

        {{-- Executive Attention Radar (Deals & At-Risk Accounts) --}}
        <div class="exec-grid-2">
            {{-- Radar Item 1: High-Value Deals Closing Soon --}}
            <div class="exec-panel">
                <div class="exec-panel-header">
                    <h4 class="exec-panel-title">
                        <x-filament::icon icon="heroicon-m-sparkles" style="width: 1rem; height: 1rem; color: #10b981;" />
                        High-Value Pipeline Deals
                    </h4>
                    <span style="font-size: 0.75rem; color: #94a3b8;">Strategic Opportunities</span>
                </div>

                @php $highDeals = $this->highValueDeals; @endphp
                @if ($highDeals->isEmpty())
                    <div style="padding: 1.5rem 0; text-align: center; font-size: 0.75rem; color: #64748b;">
                        No major deals currently in active pipeline.
                    </div>
                @else
                    <div>
                        @foreach ($highDeals as $deal)
                            <div class="exec-list-row">
                                <div>
                                    <div style="font-weight: 700;">{{ $deal->name }}</div>
                                    <div style="font-size: 0.6875rem; color: #94a3b8;">
                                        Stage: {{ $deal->stage?->name ?? 'Negotiation' }} • {{ $deal->stage?->probability ?? 50 }}% probability
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <strong style="font-size: 0.875rem; color: #10b981;">
                                        ${{ number_format((float) $deal->amount, 0) }}
                                    </strong>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Radar Item 2: At-Risk Strategic Accounts --}}
            <div class="exec-panel">
                <div class="exec-panel-header">
                    <h4 class="exec-panel-title">
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" style="width: 1rem; height: 1rem; color: #f43f5e;" />
                        At-Risk Strategic Accounts
                    </h4>
                    <span style="font-size: 0.75rem; color: #94a3b8;">Churn Prevention Required</span>
                </div>

                @php $atRiskAccounts = $this->atRiskStrategicAccounts; @endphp
                @if ($atRiskAccounts->isEmpty())
                    <div style="padding: 1.5rem 0; text-align: center; font-size: 0.75rem; color: #10b981; font-weight: 600;">
                        ✓ All accounts are in healthy or neutral standing.
                    </div>
                @else
                    <div>
                        @foreach ($atRiskAccounts as $account)
                            <div class="exec-list-row">
                                <div>
                                    <div style="font-weight: 700; display: flex; align-items: center; gap: 0.375rem;">
                                        {{ $account->name }}
                                        @if ($account->account_tier)
                                            <span class="exec-badge" style="background: #f1f5f9; color: #475569;">
                                                {{ $account->account_tier }}
                                            </span>
                                        @endif
                                    </div>
                                    <div style="font-size: 0.6875rem; color: #94a3b8;">
                                        {{ $account->domain ?? 'No domain registered' }}
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <span class="exec-badge exec-badge-rose">
                                        {{ $account->health_score }}/100 Health
                                    </span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
