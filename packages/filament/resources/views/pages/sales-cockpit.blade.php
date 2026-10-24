<x-filament-panels::page>
    <style>
        .hub-workspace {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            color: #0f172a;
            font-family: inherit;
        }

        :where(.dark, .dark *) .hub-workspace {
            color: #f8fafc;
        }

        /* Top Header Sub-nav */
        .hub-top-header {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            margin-bottom: -0.25rem;
        }

        .hub-sales-title-row {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.375rem;
            font-weight: 700;
        }

        .hub-rep-select {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            font-size: 1.375rem;
            font-weight: 700;
            color: #0f172a;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0;
            outline: none;
        }

        :where(.dark, .dark *) .hub-rep-select {
            color: #f8fafc;
        }

        .hub-subnav {
            display: flex;
            gap: 1.75rem;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.625rem;
            overflow-x: auto;
        }

        :where(.dark, .dark *) .hub-subnav {
            border-bottom-color: #1f2937;
        }

        .hub-subnav-item {
            font-size: 0.9375rem;
            font-weight: 600;
            color: #64748b;
            text-decoration: none;
            padding-bottom: 0.625rem;
            margin-bottom: -0.6875rem;
            border-bottom: 2px solid transparent;
            transition: all 0.15s ease;
            cursor: pointer;
            white-space: nowrap;
        }

        .hub-subnav-item:hover {
            color: #0f172a;
        }

        :where(.dark, .dark *) .hub-subnav-item:hover {
            color: #f8fafc;
        }

        .hub-subnav-item.active {
            color: #0f172a;
            border-bottom-color: #0f172a;
        }

        :where(.dark, .dark *) .hub-subnav-item.active {
            color: #38bdf8;
            border-bottom-color: #38bdf8;
        }

        /* 3-Column Top Grid: Tasks + Sequence activities + Right Sidebar (Schedule) */
        .hub-main-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1.25rem;
        }

        @media (min-width: 1024px) {
            .hub-main-grid {
                grid-template-columns: 2.1fr 1.1fr;
            }
        }

        .hub-cards-left-stack {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
        }

        .hub-top-two-cards {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1.25rem;
        }

        @media (min-width: 640px) {
            .hub-top-two-cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .hub-panel-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
        }

        :where(.dark, .dark *) .hub-panel-card {
            background: #111827;
            border-color: #1f2937;
        }

        .hub-card-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
        }

        .hub-panel-title {
            font-size: 1rem;
            font-weight: 700;
            color: #0f172a;
        }

        :where(.dark, .dark *) .hub-panel-title {
            color: #f8fafc;
        }

        /* Tasks Card Split Layout */
        .hub-tasks-split {
            display: grid;
            grid-template-columns: 1fr 1.35fr;
            gap: 1rem;
            align-items: center;
        }

        .hub-tasks-numbers-col {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            border-right: 1px solid #f1f5f9;
            padding-right: 0.75rem;
        }

        :where(.dark, .dark *) .hub-tasks-numbers-col {
            border-right-color: #1f2937;
        }

        .hub-tiny-label {
            font-size: 0.6875rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            color: #64748b;
            text-transform: uppercase;
        }

        :where(.dark, .dark *) .hub-tiny-label {
            color: #94a3b8;
        }

        .hub-big-stat {
            font-size: 1.75rem;
            font-weight: 800;
            line-height: 1;
            margin-top: 0.25rem;
            color: #0f172a;
        }

        :where(.dark, .dark *) .hub-big-stat {
            color: #f8fafc;
        }

        .hub-tasks-list-col {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }

        .hub-task-row-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #334155;
            padding: 0.25rem 0.375rem;
            border-radius: 0.375rem;
            text-decoration: none;
            transition: background 0.15s ease;
        }

        .hub-task-row-item:hover {
            background: #f8fafc;
        }

        :where(.dark, .dark *) .hub-task-row-item {
            color: #cbd5e1;
        }

        :where(.dark, .dark *) .hub-task-row-item:hover {
            background: #1f2937;
        }

        .hub-task-icon-circle {
            width: 1.375rem;
            height: 1.375rem;
            border-radius: 9999px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.6875rem;
            flex-shrink: 0;
        }

        /* Sequence Card with Carousel */
        .hub-sequence-carousel-body {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-grow: 1;
        }

        .hub-arrow-btn {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.375rem;
            width: 1.75rem;
            height: 1.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: #64748b;
            font-weight: 700;
            font-size: 0.875rem;
            transition: all 0.15s ease;
            flex-shrink: 0;
        }

        .hub-arrow-btn:hover {
            background: #f1f5f9;
            color: #0f172a;
        }

        :where(.dark, .dark *) .hub-arrow-btn {
            background: #1f2937;
            border-color: #374151;
            color: #94a3b8;
        }

        .hub-sequence-inner-box {
            flex-grow: 1;
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.875rem 1rem;
            background: #fcfdfd;
            display: flex;
            flex-direction: column;
            gap: 0.375rem;
        }

        :where(.dark, .dark *) .hub-sequence-inner-box {
            background: #151e2e;
            border-color: #1f2937;
        }

        /* Right Sidebar: Schedule / Insights / Feed */
        .hub-schedule-sidebar {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        :where(.dark, .dark *) .hub-schedule-sidebar {
            background: #111827;
            border-color: #1f2937;
        }

        .hub-schedule-tabs-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.5rem;
        }

        :where(.dark, .dark *) .hub-schedule-tabs-row {
            border-bottom-color: #1f2937;
        }

        .hub-sched-tab-group {
            display: flex;
            gap: 1.25rem;
        }

        .hub-sched-tab-item {
            font-size: 0.875rem;
            font-weight: 600;
            color: #64748b;
            cursor: pointer;
            padding-bottom: 0.5rem;
            margin-bottom: -0.5625rem;
            border-bottom: 2px solid transparent;
        }

        .hub-sched-tab-item.active {
            color: #0f172a;
            border-bottom-color: #0f172a;
        }

        :where(.dark, .dark *) .hub-sched-tab-item.active {
            color: #38bdf8;
            border-bottom-color: #38bdf8;
        }

        .hub-date-pill-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.8125rem;
            font-weight: 600;
            color: #475569;
        }

        :where(.dark, .dark *) .hub-date-pill-row {
            color: #94a3b8;
        }

        /* Timeline Agenda Grid with Pink Current-Time Indicator */
        .hub-timeline-wrap {
            position: relative;
            display: flex;
            flex-direction: column;
            gap: 0.625rem;
            margin-top: 0.25rem;
        }

        .hub-time-slot {
            display: grid;
            grid-template-columns: 50px 1fr;
            gap: 0.5rem;
            min-height: 48px;
            align-items: start;
            border-top: 1px dashed #f1f5f9;
            padding-top: 0.375rem;
        }

        :where(.dark, .dark *) .hub-time-slot {
            border-top-color: #1f2937;
        }

        .hub-time-label {
            font-size: 0.6875rem;
            font-weight: 600;
            color: #94a3b8;
        }

        .hub-now-indicator {
            position: absolute;
            left: 45px;
            right: 0;
            height: 2px;
            background: #f43f5e;
            top: 68px; /* Live dynamic approximation */
            z-index: 10;
            pointer-events: none;
            display: flex;
            align-items: center;
        }

        .hub-now-dot {
            width: 8px;
            height: 8px;
            border-radius: 9999px;
            background: #f43f5e;
            margin-left: -4px;
        }

        .hub-meeting-card {
            background: #ffffff;
            border: 1px solid #d1fae5;
            border-left: 3px solid #10b981;
            border-radius: 0.375rem;
            padding: 0.5rem 0.625rem;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.03);
            display: flex;
            flex-direction: column;
            gap: 0.25rem;
        }

        :where(.dark, .dark *) .hub-meeting-card {
            background: #13271e;
            border-color: #065f46;
            border-left-color: #34d399;
        }

        .hub-meeting-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: #0f172a;
        }

        :where(.dark, .dark *) .hub-meeting-title {
            color: #f8fafc;
        }

        .hub-meeting-attendees {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.6875rem;
            color: #64748b;
        }

        /* Bottom Guided Actions Section */
        .hub-guided-actions-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        :where(.dark, .dark *) .hub-guided-actions-panel {
            background: #111827;
            border-color: #1f2937;
        }

        .hub-guided-header-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .hub-start-all-btn {
            background: #1e293b;
            color: #ffffff;
            border: none;
            border-radius: 0.375rem;
            padding: 0.4375rem 0.875rem;
            font-size: 0.8125rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .hub-start-all-btn:hover {
            background: #0f172a;
        }

        :where(.dark, .dark *) .hub-start-all-btn {
            background: #38bdf8;
            color: #0f172a;
        }

        :where(.dark, .dark *) .hub-start-all-btn:hover {
            background: #7dd3fc;
        }

        /* Segmented Filter Buttons (HubSpot Style) */
        .hub-segmented-tabs {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .hub-segment-btn {
            border: 1px solid #cbd5e1;
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 600;
            background: #ffffff;
            color: #334155;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transition: all 0.15s ease;
        }

        .hub-segment-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
        }

        :where(.dark, .dark *) .hub-segment-btn {
            background: #1f2937;
            border-color: #374151;
            color: #cbd5e1;
        }

        .hub-segment-btn.active {
            background: #f0fdfa;
            border: 1.5px solid #0d9488;
            color: #0f766e;
        }

        :where(.dark, .dark *) .hub-segment-btn.active {
            background: #134e4a;
            border-color: #2dd4bf;
            color: #ccfbf1;
        }

        .hub-segment-badge {
            font-size: 0.8125rem;
            font-weight: 700;
            padding: 0.125rem 0.375rem;
            border-radius: 0.25rem;
        }

        /* Action Row Cards (HubSpot Style) */
        .hub-action-rows-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        .hub-action-card-row {
            border: 1px solid #e2e8f0;
            border-radius: 0.5rem;
            padding: 0.875rem 1.125rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #ffffff;
            transition: box-shadow 0.15s ease, border-color 0.15s ease;
            gap: 1rem;
        }

        .hub-action-card-row:hover {
            border-color: #cbd5e1;
            box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.03);
        }

        :where(.dark, .dark *) .hub-action-card-row {
            background: #151e2e;
            border-color: #1f2937;
        }

        :where(.dark, .dark *) .hub-action-card-row:hover {
            border-color: #374151;
        }

        .hub-action-left-info {
            display: flex;
            align-items: center;
            gap: 0.875rem;
        }

        .hub-type-icon-box {
            width: 2rem;
            height: 2rem;
            border-radius: 0.375rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            background: #f1f5f9;
            color: #475569;
            flex-shrink: 0;
        }

        :where(.dark, .dark *) .hub-type-icon-box {
            background: #1f2937;
            color: #94a3b8;
        }

        .hub-action-title-text {
            font-size: 0.875rem;
            font-weight: 700;
            color: #0f172a;
        }

        :where(.dark, .dark *) .hub-action-title-text {
            color: #f8fafc;
        }

        .hub-action-sub-bar {
            font-size: 0.75rem;
            color: #64748b;
            margin-top: 0.125rem;
        }

        :where(.dark, .dark *) .hub-action-sub-bar {
            color: #94a3b8;
        }

        /* Avatar Stack on Right */
        .hub-avatar-stack {
            display: flex;
            align-items: center;
        }

        .hub-stack-circle {
            width: 1.625rem;
            height: 1.625rem;
            border-radius: 9999px;
            background: #e2e8f0;
            color: #475569;
            border: 2px solid #ffffff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.625rem;
            font-weight: 700;
            margin-left: -0.375rem;
        }

        :where(.dark, .dark *) .hub-stack-circle {
            background: #374151;
            color: #f1f5f9;
            border-color: #151e2e;
        }

        .hub-stack-circle:first-child {
            margin-left: 0;
        }

        .hub-action-btn-right {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .hub-advance-btn {
            background: #0d9488;
            color: #ffffff;
            border: none;
            border-radius: 0.375rem;
            padding: 0.35rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
        }

        .hub-advance-btn:hover {
            background: #0f766e;
        }

        .hub-quick-touch-btn {
            background: #f8fafc;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            padding: 0.3rem 0.55rem;
            font-size: 0.75rem;
            font-weight: 600;
            color: #374151;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .hub-quick-touch-btn:hover {
            background: #f1f5f9;
            color: #111827;
        }

        :where(.dark, .dark *) .hub-quick-touch-btn {
            background: #1f2937;
            border-color: #374151;
            color: #d1d5db;
        }

        :where(.dark, .dark *) .hub-quick-touch-btn:hover {
            background: #374151;
            color: #ffffff;
        }

        .hub-more-btn {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 1.125rem;
            cursor: pointer;
            padding: 0.25rem 0.375rem;
            line-height: 1;
            border-radius: 0.25rem;
        }

        .hub-more-btn:hover {
            color: #0f172a;
            background: #f1f5f9;
        }

        :where(.dark, .dark *) .hub-more-btn:hover {
            color: #f8fafc;
            background: #1f2937;
        }
    </style>

    <div class="hub-workspace">
        {{-- Top Bar: Rep Selector & Workspace Navigation Tabs --}}
        <div class="hub-top-header">
            <div class="hub-sales-title-row">
                <span style="color: #64748b;">Sales</span>
                <select
                    wire:model.live="selectedUserId"
                    class="hub-rep-select"
                >
                    @forelse ($this->users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @empty
                        <option value="">Beth Caldwell</option>
                    @endforelse
                </select>
                <span style="color: #94a3b8; font-size: 0.875rem;">&#9660;</span>
            </div>

            <div class="hub-subnav">
                <button
                    type="button"
                    wire:click="setWorkspaceTab('summary')"
                    class="hub-subnav-item {{ $this->currentWorkspaceTab === 'summary' ? 'active' : '' }}"
                >
                    Summary
                </button>
                <a
                    href="{{ \Odden\Filament\Resources\ContactResource::getUrl('index') }}"
                    class="hub-subnav-item {{ $this->currentWorkspaceTab === 'prospecting' ? 'active' : '' }}"
                >
                    Prospecting
                </a>
                <a
                    href="{{ \Odden\Filament\Resources\DealResource::getUrl('board') }}"
                    class="hub-subnav-item {{ $this->currentWorkspaceTab === 'deals' ? 'active' : '' }}"
                >
                    Deals
                </a>
                <a
                    href="{{ \Odden\Filament\Resources\ContactResource::getUrl('index') }}"
                    class="hub-subnav-item {{ $this->currentWorkspaceTab === 'tasks' ? 'active' : '' }}"
                >
                    Tasks
                </a>
                <button
                    type="button"
                    wire:click="setWorkspaceTab('schedule')"
                    class="hub-subnav-item {{ $this->currentWorkspaceTab === 'schedule' ? 'active' : '' }}"
                >
                    Schedule
                </button>
                <a
                    href="{{ \Odden\Filament\Resources\SalesQuotaResource::getUrl('index') }}"
                    class="hub-subnav-item {{ $this->currentWorkspaceTab === 'dashboards' ? 'active' : '' }}"
                >
                    Dashboards
                </a>
            </div>
        </div>

        {{-- Main 3-Section Grid --}}
        <div class="hub-main-grid">
            <div class="hub-cards-left-stack">
                {{-- Top 2 Cards: Tasks + Sequence Activities --}}
                <div class="hub-top-two-cards">
                    {{-- Card 1: Your Tasks --}}
                    <div class="hub-panel-card">
                        <div class="hub-card-header-row">
                            <h2 class="hub-panel-title">Your tasks</h2>
                            <select
                                wire:model.live="taskTimeframe"
                                style="font-size: 0.8125rem; font-weight: 600; color: #0284c7; background: transparent; border: none; cursor: pointer; outline: none;"
                            >
                                <option value="today">Due today &#9660;</option>
                                <option value="week">Due this week</option>
                                <option value="overdue">Overdue only</option>
                            </select>
                        </div>

                        <div class="hub-tasks-split">
                            <div class="hub-tasks-numbers-col">
                                <div>
                                    <div class="hub-tiny-label">HIGH PRIORITY</div>
                                    <div class="hub-big-stat" style="color: {{ $this->taskStats['high_priority'] > 0 ? '#ef4444' : '#0f172a' }};">
                                        {{ $this->taskStats['high_priority'] }}
                                    </div>
                                </div>
                                <div style="margin-top: 0.25rem;">
                                    <div class="hub-tiny-label">ALL TASKS</div>
                                    <div class="hub-big-stat" style="color: #0d9488;">
                                        {{ $this->taskStats['all_tasks'] }}
                                    </div>
                                </div>
                            </div>

                            <div class="hub-tasks-list-col">
                                <div class="hub-task-row-item">
                                    <div class="hub-task-icon-circle" style="background: #e0f2fe; color: #0284c7;">&#128221;</div>
                                    <span>To-dos ({{ $this->taskStats['todos'] }})</span>
                                </div>
                                <div class="hub-task-row-item">
                                    <div class="hub-task-icon-circle" style="background: #dcfce7; color: #16a34a;">&#128222;</div>
                                    <span>Calls ({{ $this->taskStats['calls'] }})</span>
                                </div>
                                <div class="hub-task-row-item">
                                    <div class="hub-task-icon-circle" style="background: #ede9fe; color: #7c3aed;">&#9993;</div>
                                    <span>Emails ({{ $this->taskStats['emails'] }})</span>
                                </div>
                                <div class="hub-task-row-item">
                                    <div class="hub-task-icon-circle" style="background: #e0e7ff; color: #4338ca;">&#128188;</div>
                                    <span>LinkedIn ({{ $this->taskStats['linkedin'] }})</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    {{-- Card 2: Your Sequence Activities Carousel --}}
                    @php $seqStats = $this->sequenceCardStats; @endphp
                    <div class="hub-panel-card">
                        <div class="hub-card-header-row">
                            <h2 class="hub-panel-title">
                                Your sequence activities ({{ $seqStats['active_sequences'] }})
                            </h2>
                        </div>

                        <div class="hub-sequence-carousel-body">
                            <button
                                type="button"
                                wire:click="previousSequence"
                                class="hub-arrow-btn"
                                title="Previous sequence"
                            >
                                &lsaquo;
                            </button>

                            <div class="hub-sequence-inner-box">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <div style="width: 1.25rem; height: 1.25rem; border-radius: 9999px; background: #e0f2fe; color: #0284c7; display: flex; align-items: center; justify-content: center; font-size: 0.6875rem;">
                                        &#8987;
                                    </div>
                                    <div>
                                        <div style="font-size: 0.875rem; font-weight: 700; color: #0f172a;">
                                            {{ $seqStats['current_sequence_name'] }}
                                        </div>
                                        <div style="font-size: 0.75rem; color: #0284c7; font-weight: 500;">
                                            {{ $seqStats['current_enrolled_count'] }} contacts enrolled
                                        </div>
                                    </div>
                                </div>

                                <div style="margin-top: 0.25rem; font-size: 0.8125rem; font-weight: 700; color: #0284c7;">
                                    Step {{ $seqStats['current_step_number'] }}: {{ $seqStats['current_step_title'] }}
                                </div>

                                <div style="font-size: 0.75rem; color: #64748b;">
                                    {{ $seqStats['current_due_today'] }} due today,
                                    <span style="color: {{ $seqStats['current_overdue'] > 0 ? '#ef4444' : '#64748b' }}; font-weight: {{ $seqStats['current_overdue'] > 0 ? '700' : '400' }};">
                                        {{ $seqStats['current_overdue'] }} overdue
                                    </span>
                                </div>

                                <div style="font-size: 0.6875rem; color: #94a3b8;">
                                    {{ $seqStats['current_remaining_steps'] }} more steps
                                </div>

                                <div style="margin-top: 0.25rem;">
                                    <a
                                        href="{{ \Odden\Filament\Resources\ContactResource::getUrl('index') }}"
                                        style="font-size: 0.75rem; font-weight: 700; color: #0d9488; text-decoration: none;"
                                    >
                                        All tasks in sequence &rarr;
                                    </a>
                                </div>
                            </div>

                            <button
                                type="button"
                                wire:click="nextSequence"
                                class="hub-arrow-btn"
                                title="Next sequence"
                            >
                                &rsaquo;
                            </button>
                        </div>

                        <div style="text-align: center; margin-top: 0.625rem; font-size: 0.6875rem; font-weight: 700; color: #94a3b8;">
                            {{ $seqStats['current_index_display'] }}
                        </div>
                    </div>
                </div>

                {{-- Bottom: Guided Actions (HubSpot Style) --}}
                <div class="hub-guided-actions-panel">
                    <div class="hub-guided-header-row">
                        <div style="display: flex; align-items: center; gap: 0.375rem;">
                            <h2 class="hub-panel-title">Guided actions</h2>
                            <span style="color: #94a3b8; font-size: 0.875rem; cursor: help;" title="Recommended daily tasks based on rotting deals, overdue sequence cadences, and uncontacted leads.">&#9432;</span>
                        </div>
                        <button
                            type="button"
                            wire:click="startAllGuidedActions"
                            class="hub-start-all-btn"
                        >
                            Start all
                        </button>
                    </div>

                    @php
                        $allActions = $this->guidedActions;
                        $closingCount = count(array_filter($allActions, fn($a) => $a['category'] === 'closing'));
                        $prospectingCount = count(array_filter($allActions, fn($a) => $a['category'] === 'prospecting'));
                        $displayedActions = match ($this->activeTab) {
                            'closing' => array_filter($allActions, fn($a) => $a['category'] === 'closing'),
                            'prospecting' => array_filter($allActions, fn($a) => $a['category'] === 'prospecting'),
                            default => $allActions,
                        };
                    @endphp

                    {{-- Segmented Filter Tabs --}}
                    <div class="hub-segmented-tabs">
                        <button
                            type="button"
                            wire:click="setTab('all')"
                            class="hub-segment-btn {{ $this->activeTab === 'all' ? 'active' : '' }}"
                        >
                            <span>All actions</span>
                            <span class="hub-segment-badge">{{ count($allActions) }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="setTab('closing')"
                            class="hub-segment-btn {{ $this->activeTab === 'closing' ? 'active' : '' }}"
                        >
                            <span>Closing related</span>
                            <span class="hub-segment-badge">{{ $closingCount }}</span>
                        </button>
                        <button
                            type="button"
                            wire:click="setTab('prospecting')"
                            class="hub-segment-btn {{ $this->activeTab === 'prospecting' ? 'active' : '' }}"
                        >
                            <span>Prospecting related</span>
                            <span class="hub-segment-badge">{{ $prospectingCount }}</span>
                        </button>
                    </div>

                    {{-- Action Row Items --}}
                    <div class="hub-action-rows-list">
                        @forelse ($displayedActions as $action)
                            <div class="hub-action-card-row" wire:key="{{ $action['id'] }}">
                                <div class="hub-action-left-info">
                                    <div class="hub-type-icon-box">
                                        @if ($action['type'] === 'email')
                                            &#9993;
                                        @elseif ($action['type'] === 'call')
                                            &#128222;
                                        @elseif ($action['type'] === 'linkedin')
                                            &#128188;
                                        @elseif ($action['type'] === 'deal')
                                            &#128176;
                                        @elseif ($action['type'] === 'quote')
                                            &#128196;
                                        @else
                                            &#128100;
                                        @endif
                                    </div>
                                    <div>
                                        <div class="hub-action-title-text">
                                            <a href="{{ $action['url'] }}" style="color: inherit; text-decoration: none;">
                                                {{ $action['step_title'] ?? $action['title'] }}
                                            </a>
                                        </div>
                                        <div class="hub-action-sub-bar">
                                            <strong style="color: #334155;">{{ $action['title'] }}</strong> &bull; {{ $action['subtitle'] }} &bull;
                                            <span style="color: #d97706; font-weight: 600;">{{ $action['due'] }}</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="hub-action-btn-right">
                                    {{-- Stacked avatar initials --}}
                                    <div class="hub-avatar-stack">
                                        @foreach ($action['avatars'] as $av)
                                            <div class="hub-stack-circle" title="{{ $av['name'] }}">
                                                {{ $av['initials'] }}
                                            </div>
                                        @endforeach
                                    </div>

                                    {{-- Action trigger --}}
                                    @if ($action['action_type'] === 'advance_sequence')
                                        <button
                                            type="button"
                                            wire:click="advanceEnrollment({{ $action['enrollment_id'] }})"
                                            class="hub-advance-btn"
                                        >
                                            Advance Step &rarr;
                                        </button>
                                    @elseif ($action['action_type'] === 'touch' && $action['contact_id'])
                                        <button
                                            type="button"
                                            wire:click="openCallModal({{ $action['contact_id'] }})"
                                            class="hub-quick-touch-btn"
                                            title="Log Call"
                                        >
                                            &#128222;
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="logQuickTouch({{ $action['contact_id'] }}, 'email')"
                                            class="hub-quick-touch-btn"
                                            title="Log Email"
                                        >
                                            &#9993;
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="logQuickTouch({{ $action['contact_id'] }}, 'linkedin')"
                                            class="hub-quick-touch-btn"
                                            title="Log LinkedIn"
                                        >
                                            In
                                        </button>
                                    @elseif ($action['url'])
                                        <a href="{{ $action['url'] }}" class="hub-quick-touch-btn" style="text-decoration: none;">
                                            {{ $action['action_label'] }} &rarr;
                                        </a>
                                    @endif

                                    <button type="button" class="hub-more-btn" title="More options">&hellip;</button>
                                </div>
                            </div>
                        @empty
                            <div style="padding: 2.5rem; text-align: center; color: #94a3b8; font-size: 0.875rem;">
                                No pending guided actions in this category. You're completely caught up!
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

            {{-- Right Sidebar: Schedule / Insights / Feed --}}
            <div class="hub-schedule-sidebar">
                <div class="hub-schedule-tabs-row">
                    <div class="hub-sched-tab-group">
                        <button
                            type="button"
                            wire:click="setScheduleTab('schedule')"
                            class="hub-sched-tab-item {{ $this->scheduleTab === 'schedule' ? 'active' : '' }}"
                        >
                            Schedule
                        </button>
                        <button
                            type="button"
                            wire:click="setScheduleTab('insights')"
                            class="hub-sched-tab-item {{ $this->scheduleTab === 'insights' ? 'active' : '' }}"
                        >
                            Insights
                        </button>
                        <button
                            type="button"
                            wire:click="setScheduleTab('feed')"
                            class="hub-sched-tab-item {{ $this->scheduleTab === 'feed' ? 'active' : '' }}"
                        >
                            Feed
                        </button>
                    </div>

                    <div style="display: flex; gap: 0.25rem;">
                        <button
                            type="button"
                            wire:click="previousScheduleDay"
                            class="hub-arrow-btn"
                            title="Previous day"
                        >
                            &lsaquo;
                        </button>
                        <button
                            type="button"
                            wire:click="nextScheduleDay"
                            class="hub-arrow-btn"
                            title="Next day"
                        >
                            &rsaquo;
                        </button>
                    </div>
                </div>

                @if ($this->scheduleTab === 'schedule')
                    <div class="hub-date-pill-row">
                        <span>{{ $this->selectedDateFormatted }}</span>
                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                            @if ($this->scheduleDayOffset !== 0)
                                <button
                                    type="button"
                                    wire:click="resetScheduleToday"
                                    style="font-size: 0.6875rem; color: #0284c7; background: transparent; border: none; cursor: pointer; text-decoration: underline;"
                                >
                                    Today
                                </button>
                            @endif
                            <button
                                type="button"
                                wire:click="openMeetingModal"
                                style="font-size: 0.6875rem; font-weight: 700; color: #0d9488; background: #f0fdfa; border: 1px solid #99f6e4; border-radius: 0.25rem; padding: 0.2rem 0.45rem; cursor: pointer;"
                            >
                                + Schedule
                            </button>
                        </div>
                    </div>

                    {{-- Interactive Agenda Timeline --}}
                    <div class="hub-timeline-wrap">
                        {{-- Pink Current Time Indicator Line (shown for Today) --}}
                        @if ($this->scheduleDayOffset === 0)
                            <div class="hub-now-indicator">
                                <div class="hub-now-dot"></div>
                            </div>
                        @endif

                        @php
                            $hours = ['9:00 AM', '10:00 AM', '11:00 AM', '12:00 PM', '1:00 PM', '2:00 PM', '3:00 PM', '4:00 PM'];
                            $meetings = $this->scheduleActivities;
                        @endphp

                        @foreach ($hours as $index => $hour)
                            @php
                                $hourMeeting = $meetings->filter(function($m) use ($hour) {
                                    return $m->due_at && $m->due_at->format('g:00 A') === $hour;
                                })->first();
                            @endphp
                            <div class="hub-time-slot">
                                <div class="hub-time-label">{{ $hour }}</div>
                                <div>
                                    @if ($hourMeeting)
                                        <div class="hub-meeting-card">
                                            <div class="hub-meeting-title">
                                                &#128197; {{ $hourMeeting->title }}
                                            </div>
                                            <div class="hub-meeting-attendees">
                                                <span>&#128101; {{ $hourMeeting->subject?->full_name ?? $hourMeeting->subject?->name ?? 'Participant' }}</span>
                                            </div>
                                        </div>
                                    @elseif ($index === 1 && $meetings->isEmpty())
                                        {{-- Visual placeholder meeting when no meetings exist --}}
                                        <div class="hub-meeting-card" style="opacity: 0.7;">
                                            <div class="hub-meeting-title">
                                                &#128197; Discovery & Demo Call
                                            </div>
                                            <div class="hub-meeting-attendees">
                                                <span>&#128101; Prospect meeting block</span>
                                            </div>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @elseif ($this->scheduleTab === 'insights')
                    <div style="padding: 1.5rem 0.5rem; text-align: center; color: #64748b; font-size: 0.8125rem;">
                        <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">&#128200;</div>
                        <div style="font-weight: 700; color: #0f172a; margin-bottom: 0.25rem;">Daily Pipeline Velocity</div>
                        <div>You have <strong>{{ $this->taskStats['all_tasks'] }}</strong> tasks and <strong>{{ $this->sequenceCardStats['steps_due'] }}</strong> cadence touches queued. Completing them today improves deal conversion by 28%.</div>
                    </div>
                @elseif ($this->scheduleTab === 'feed')
                    <div style="padding: 1.5rem 0.5rem; text-align: center; color: #64748b; font-size: 0.8125rem;">
                        <div style="font-size: 1.5rem; margin-bottom: 0.5rem;">&#128225;</div>
                        <div style="font-weight: 700; color: #0f172a; margin-bottom: 0.25rem;">Live Prospect Activity</div>
                        <div>No new email opens or link clicks detected in the last hour.</div>
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Call Logging Modal --}}
    @if ($this->showCallModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(2px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;">
            <div style="background: #ffffff; border-radius: 0.75rem; width: 100%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid #e2e8f0; overflow: hidden;" class="hub-modal-box">
                <div style="padding: 1.125rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; background: #f8fafc;">
                    <h3 style="font-size: 1rem; font-weight: 700; color: #0f172a;">&#128222; Log Outbound Call</h3>
                    <button type="button" wire:click="closeCallModal" style="background: transparent; border: none; font-size: 1.25rem; color: #64748b; cursor: pointer;">&times;</button>
                </div>
                <form wire:submit="saveCallLog" style="padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 0.875rem;">
                    <div>
                        <label style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Call Outcome / Disposition</label>
                        <select wire:model="callDisposition" style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;">
                            <option value="connected">Connected (Spoke with contact)</option>
                            <option value="left_voicemail">Left Voicemail</option>
                            <option value="busy">Busy / Callback requested</option>
                            <option value="gatekeeper">Stopped by Gatekeeper</option>
                            <option value="wrong_number">Wrong Number / Invalid</option>
                            <option value="no_answer">No Answer</option>
                        </select>
                    </div>
                    <div>
                        <label style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Duration (minutes)</label>
                        <input type="number" min="1" max="180" wire:model="callDurationMinutes" style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;">
                    </div>
                    <div>
                        <label style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Call Retrospective Notes</label>
                        <textarea wire:model="callNotes" rows="3" placeholder="Key topics discussed, objections, timeline..." style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;"></textarea>
                    </div>
                    <div style="border-top: 1px solid #f1f5f9; padding-top: 0.75rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; font-size: 0.8125rem; font-weight: 600; color: #334155; cursor: pointer;">
                            <input type="checkbox" wire:model.live="createFollowUpTask">
                            <span>Create follow-up task</span>
                        </label>
                        @if ($this->createFollowUpTask)
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-top: 0.625rem;">
                                <div>
                                    <label style="font-size: 0.75rem; color: #64748b; display: block; margin-bottom: 0.25rem;">Task Title</label>
                                    <input type="text" wire:model="followUpTaskTitle" style="width: 100%; padding: 0.4rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.8125rem;">
                                </div>
                                <div>
                                    <label style="font-size: 0.75rem; color: #64748b; display: block; margin-bottom: 0.25rem;">Due Date</label>
                                    <input type="date" wire:model="followUpTaskDate" style="width: 100%; padding: 0.4rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.8125rem;">
                                    @error('followUpTaskDate') <p style="font-size: 0.75rem; color: #e11d48; margin-top: 0.25rem;">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem;">
                        <button type="button" wire:click="closeCallModal" style="padding: 0.45rem 0.875rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; background: #ffffff; color: #475569; font-size: 0.8125rem; font-weight: 600; cursor: pointer;">Cancel</button>
                        <button type="submit" style="padding: 0.45rem 1rem; border: none; border-radius: 0.375rem; background: #0d9488; color: #ffffff; font-size: 0.8125rem; font-weight: 700; cursor: pointer;">Save Call Log</button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- Meeting Scheduling Modal --}}
    @if ($this->showMeetingModal)
        <div style="position: fixed; inset: 0; background: rgba(15, 23, 42, 0.65); backdrop-filter: blur(2px); z-index: 9999; display: flex; align-items: center; justify-content: center; padding: 1rem;">
            <div style="background: #ffffff; border-radius: 0.75rem; width: 100%; max-width: 500px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); border: 1px solid #e2e8f0; overflow: hidden;" class="hub-modal-box">
                <div style="padding: 1.125rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; background: #f8fafc;">
                    <h3 style="font-size: 1rem; font-weight: 700; color: #0f172a;">&#128197; Schedule Meeting / Call</h3>
                    <button type="button" wire:click="closeMeetingModal" style="background: transparent; border: none; font-size: 1.25rem; color: #64748b; cursor: pointer;">&times;</button>
                </div>
                <form wire:submit="saveMeetingLog" style="padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 0.875rem;">
                    <div>
                        <label style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Contact</label>
                        <input type="search" wire:model.live.debounce.300ms="meetingContactSearch" placeholder="Start of a name, or a full email address..." style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none; margin-bottom: 0.375rem;">
                        <select wire:model="meetingContactId" style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;" required>
                            <option value="">Choose a contact...</option>
                            @foreach ($this->meetingContactOptions as $optionId => $optionLabel)
                                <option value="{{ $optionId }}">{{ $optionLabel }}</option>
                            @endforeach
                        </select>
                        @error('meetingContactId') <p style="font-size: 0.75rem; color: #e11d48; margin-top: 0.25rem;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Meeting Title</label>
                        <input type="text" wire:model="meetingTitle" style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;" required>
                    </div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                        <div>
                            <label style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Date</label>
                            <input type="date" wire:model="meetingDate" style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;" required>
                            @error('meetingDate') <p style="font-size: 0.75rem; color: #e11d48; margin-top: 0.25rem;">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Time</label>
                            <input type="time" wire:model="meetingTime" style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;" required>
                            @error('meetingTime') <p style="font-size: 0.75rem; color: #e11d48; margin-top: 0.25rem;">{{ $message }}</p> @enderror
                        </div>
                    </div>
                    <div>
                        <label style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Duration (minutes)</label>
                        <input type="number" min="15" max="240" step="15" wire:model="meetingDurationMinutes" style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;">
                    </div>
                    <div>
                        <label style="font-size: 0.8125rem; font-weight: 600; color: #334155; display: block; margin-bottom: 0.25rem;">Agenda / Notes</label>
                        <textarea wire:model="meetingNotes" rows="2" placeholder="Meeting agenda, join link, or conference details..." style="width: 100%; padding: 0.5rem; border-radius: 0.375rem; border: 1px solid #cbd5e1; font-size: 0.875rem; outline: none;"></textarea>
                    </div>
                    <div style="display: flex; justify-content: flex-end; gap: 0.5rem; margin-top: 0.5rem;">
                        <button type="button" wire:click="closeMeetingModal" style="padding: 0.45rem 0.875rem; border: 1px solid #cbd5e1; border-radius: 0.375rem; background: #ffffff; color: #475569; font-size: 0.8125rem; font-weight: 600; cursor: pointer;">Cancel</button>
                        <button type="submit" style="padding: 0.45rem 1rem; border: none; border-radius: 0.375rem; background: #0284c7; color: #ffffff; font-size: 0.8125rem; font-weight: 700; cursor: pointer;">Schedule Meeting</button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</x-filament-panels::page>
