<x-filament-panels::page>
    <style>
        .dq-container {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            color: #0f172a;
            font-family: inherit;
        }

        :where(.dark, .dark *) .dq-container {
            color: #f8fafc;
        }

        .dq-header {
            display: flex;
            flex-direction: column;
            gap: 1rem;
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem 1.5rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
        }

        :where(.dark, .dark *) .dq-header {
            background: #111827;
            border-color: #1f2937;
        }

        @media (min-width: 640px) {
            .dq-header {
                flex-direction: row;
                align-items: center;
                justify-content: space-between;
            }
        }

        .dq-title {
            font-size: 1.25rem;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            color: #0f172a;
            letter-spacing: -0.02em;
        }

        :where(.dark, .dark *) .dq-title {
            color: #ffffff;
        }

        .dq-subtitle {
            font-size: 0.8125rem;
            color: #64748b;
            margin-top: 0.25rem;
        }

        :where(.dark, .dark *) .dq-subtitle {
            color: #94a3b8;
        }

        .dq-stats-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 1rem;
        }

        @media (min-width: 768px) {
            .dq-stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        .dq-card {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            padding: 1.25rem;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }

        :where(.dark, .dark *) .dq-card {
            background: #111827;
            border-color: #1f2937;
        }

        .dq-card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 0.25rem;
        }

        .dq-card-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: #64748b;
        }

        :where(.dark, .dark *) .dq-card-label {
            color: #94a3b8;
        }

        .dq-card-value {
            font-size: 1.625rem;
            font-weight: 900;
            color: #0f172a;
        }

        :where(.dark, .dark *) .dq-card-value {
            color: #ffffff;
        }

        .dq-tabs {
            display: flex;
            gap: 0.5rem;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.5rem;
        }

        :where(.dark, .dark *) .dq-tabs {
            border-bottom-color: #374151;
        }

        .dq-tab-btn {
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
            font-weight: 700;
            border-radius: 0.5rem;
            background: transparent;
            color: #64748b;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        :where(.dark, .dark *) .dq-tab-btn {
            color: #9ca3af;
        }

        .dq-tab-btn.active {
            background: #fff7ed;
            color: #ea580c;
            border-color: #fed7aa;
        }

        :where(.dark, .dark *) .dq-tab-btn.active {
            background: #431407;
            color: #fdba74;
            border-color: #9a3412;
        }

        .dq-panel {
            background: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 0.75rem;
            overflow: hidden;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.04);
        }

        :where(.dark, .dark *) .dq-panel {
            background: #111827;
            border-color: #1f2937;
        }

        .dq-panel-header {
            padding: 1.25rem;
            border-bottom: 1px solid #f1f5f9;
        }

        :where(.dark, .dark *) .dq-panel-header {
            border-bottom-color: #1f2937;
        }

        .dq-dupe-group {
            padding: 1.25rem;
            border-bottom: 1px solid #f1f5f9;
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }

        :where(.dark, .dark *) .dq-dupe-group {
            border-bottom-color: #1f2937;
        }

        .dq-dupe-group:last-child {
            border-bottom: none;
        }

        .dq-dupe-grid {
            display: grid;
            grid-template-columns: repeat(1, minmax(0, 1fr));
            gap: 0.75rem;
        }

        @media (min-width: 768px) {
            .dq-dupe-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .dq-record-card {
            padding: 0.875rem;
            border-radius: 0.5rem;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            font-size: 0.75rem;
        }

        :where(.dark, .dark *) .dq-record-card {
            background: #1e293b;
            border-color: #334155;
        }

        .dq-record-card.primary-candidate {
            border-color: #fdba74;
            background: #fff7ed;
        }

        :where(.dark, .dark *) .dq-record-card.primary-candidate {
            border-color: #9a3412;
            background: #431407;
        }

        .dq-btn-merge {
            display: inline-flex;
            align-items: center;
            gap: 0.375rem;
            padding: 0.375rem 0.875rem;
            font-size: 0.75rem;
            font-weight: 700;
            color: #ffffff;
            background: #f97316;
            border: none;
            border-radius: 0.5rem;
            cursor: pointer;
            transition: all 0.15s ease;
        }

        .dq-btn-merge:hover {
            background: #ea580c;
        }

        .dq-badge-match {
            display: inline-flex;
            align-items: center;
            padding: 0.125rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.6875rem;
            font-weight: 700;
            background: #fef3c7;
            color: #b45309;
            border: 1px solid #fde68a;
            text-transform: uppercase;
        }

        :where(.dark, .dark *) .dq-badge-match {
            background: #451a03;
            color: #fbbf24;
            border-color: #78350f;
        }

        .dq-container svg {
            display: inline-block;
            vertical-align: middle;
            flex-shrink: 0;
        }
    </style>

    <div class="dq-container">
        {{-- Top Header Banner --}}
        <div class="dq-header">
            <div>
                <h2 class="dq-title">
                    <x-filament::icon icon="heroicon-m-sparkles" style="width: 1.5rem; height: 1.5rem; color: #f97316;" />
                    Duplicates and missing details
                </h2>
                <p class="dq-subtitle">
                    Detect duplicate records, reconcile conflicting fields, and merge CRM records without losing timeline history.
                </p>
            </div>

            <div style="text-align: right;">
                <div style="font-size: 0.75rem; color: #94a3b8; font-weight: 600; text-transform: uppercase;">Without duplicates</div>
                <div style="font-size: 1.625rem; font-weight: 900; color: #10b981;">
                    {{ $this->dataCleanlinessScore }}%
                </div>
            </div>
        </div>

        {{-- Top Summary Stats --}}
        @php
            $dupeContacts = $this->duplicateContacts;
            $dupeCompanies = $this->duplicateCompanies;
        @endphp
        <div class="dq-stats-grid">
            <div class="dq-card">
                <div class="dq-card-header">
                    <span class="dq-card-label">Duplicate Contact Sets</span>
                    <x-filament::icon icon="heroicon-m-user-group" style="width: 1.25rem; height: 1.25rem; color: #6366f1;" />
                </div>
                <div class="dq-card-value" style="color: {{ count($dupeContacts) > 0 ? '#f59e0b' : 'inherit' }};">
                    {{ count($dupeContacts) }}
                </div>
                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">
                    Across {{ number_format($this->totalContactsCount) }} total contacts
                </div>
            </div>

            <div class="dq-card">
                <div class="dq-card-header">
                    <span class="dq-card-label">Duplicate Company Sets</span>
                    <x-filament::icon icon="heroicon-m-building-office" style="width: 1.25rem; height: 1.25rem; color: #10b981;" />
                </div>
                <div class="dq-card-value" style="color: {{ count($dupeCompanies) > 0 ? '#f59e0b' : 'inherit' }};">
                    {{ count($dupeCompanies) }}
                </div>
                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">
                    Across {{ number_format($this->totalCompaniesCount) }} total companies
                </div>
            </div>

            <div class="dq-card">
                <div class="dq-card-header">
                    <span class="dq-card-label">When you merge</span>
                    <x-filament::icon icon="heroicon-m-shield-check" style="width: 1.25rem; height: 1.25rem; color: #10b981;" />
                </div>
                <div style="font-size: 0.875rem; font-weight: 700; color: #10b981; display: flex; align-items: center; gap: 0.375rem; margin-top: 0.25rem;">
                    <x-filament::icon icon="heroicon-m-check-circle" style="width: 1rem; height: 1rem;" />
                    Nothing linked is lost
                </div>
                <div style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">
                    Activities, deals and links move to the record you keep; the duplicate goes to the trash, where it can be restored.
                </div>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="dq-tabs">
            <button
                type="button"
                wire:click="setActiveTab('contacts')"
                class="dq-tab-btn {{ $activeTab === 'contacts' ? 'active' : '' }}">
                Contacts ({{ count($dupeContacts) }} sets)
            </button>
            <button
                type="button"
                wire:click="setActiveTab('companies')"
                class="dq-tab-btn {{ $activeTab === 'companies' ? 'active' : '' }}">
                Companies ({{ count($dupeCompanies) }} sets)
            </button>
        </div>

        {{-- Tab Content: Contacts --}}
        @if ($activeTab === 'contacts')
            <div class="dq-panel">
                <div class="dq-panel-header">
                    <h3 style="font-size: 0.9375rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem;">
                        <x-filament::icon icon="heroicon-m-user-group" style="width: 1.25rem; height: 1.25rem; color: #6366f1;" />
                        Duplicate Contact Review & Resolution
                    </h3>
                    <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">
                        Choose the primary record to keep. The secondary record will be merged into the primary with all timeline activities preserved.
                    </p>
                </div>

                @if (empty($dupeContacts))
                    <div style="padding: 2.5rem; text-align: center;">
                        <x-filament::icon icon="heroicon-o-check-circle" style="width: 3rem; height: 3rem; color: #10b981; margin: 0 auto 0.5rem auto;" />
                        <h4 style="font-size: 0.875rem; font-weight: 700;">Clean Contact Database</h4>
                        <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">No duplicate contacts detected based on email or phone.</p>
                    </div>
                @else
                    <div>
                        @foreach ($dupeContacts as $group)
                            <div class="dq-dupe-group">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span class="dq-badge-match">
                                        Matched on {{ $group['match_field'] }}: {{ $group['match_value'] }}
                                    </span>
                                    <span style="font-size: 0.75rem; color: #94a3b8;">({{ $group['contacts']->count() }} records found)</span>
                                </div>

                                <div class="dq-dupe-grid">
                                    @php
                                        $primaryContact = $group['contacts']->first();
                                        $secondaryContact = $group['contacts']->last();
                                    @endphp
                                    @foreach ($group['contacts'] as $contact)
                                        <div class="dq-record-card {{ $loop->first ? 'primary-candidate' : '' }}">
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <div style="font-weight: 700; font-size: 0.875rem;">
                                                    {{ $contact->first_name }} {{ $contact->last_name }}
                                                    @if ($loop->first)
                                                        <span style="font-size: 0.625rem; font-weight: 700; background: #ffedd5; color: #c2410c; padding: 0.125rem 0.375rem; border-radius: 0.25rem; margin-left: 0.25rem;">
                                                            Primary Suggestion
                                                        </span>
                                                    @endif
                                                </div>
                                                <span style="color: #94a3b8; font-size: 0.6875rem;">ID #{{ $contact->id }}</span>
                                            </div>
                                            <div style="margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.25rem;">
                                                <div>Email: <strong>{{ $contact->email }}</strong></div>
                                                <div>Phone: <strong>{{ $contact->phone ?? '—' }}</strong></div>
                                                <div>Lead Score: <strong style="color: #f97316;">{{ $contact->lead_score }} pts</strong></div>
                                                <div style="color: #94a3b8;">Created: {{ $contact->created_at?->diffForHumans() }}</div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($primaryContact && $secondaryContact && $primaryContact->id !== $secondaryContact->id)
                                    <div style="display: flex; justify-content: flex-end; padding-top: 0.25rem;">
                                        <button
                                            type="button"
                                            wire:click="mergeContacts({{ $primaryContact->id }}, {{ $secondaryContact->id }})"
                                            wire:confirm="Merge {{ $secondaryContact->email }} into {{ $primaryContact->email }}? This will preserve all activities and delete the duplicate."
                                            class="dq-btn-merge">
                                            <x-filament::icon icon="heroicon-m-arrows-right-left" style="width: 0.875rem; height: 0.875rem;" />
                                            Merge Duplicate into #{{ $primaryContact->id }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- Tab Content: Companies --}}
        @if ($activeTab === 'companies')
            <div class="dq-panel">
                <div class="dq-panel-header">
                    <h3 style="font-size: 0.9375rem; font-weight: 800; display: flex; align-items: center; gap: 0.5rem;">
                        <x-filament::icon icon="heroicon-m-building-office" style="width: 1.25rem; height: 1.25rem; color: #10b981;" />
                        Duplicate Company Review & Resolution
                    </h3>
                    <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">
                        Consolidate duplicate accounts sharing domains or legal names. Associated contacts, tickets, and activities will be united.
                    </p>
                </div>

                @if (empty($dupeCompanies))
                    <div style="padding: 2.5rem; text-align: center;">
                        <x-filament::icon icon="heroicon-o-check-circle" style="width: 3rem; height: 3rem; color: #10b981; margin: 0 auto 0.5rem auto;" />
                        <h4 style="font-size: 0.875rem; font-weight: 700;">Clean Company Database</h4>
                        <p style="font-size: 0.75rem; color: #94a3b8; margin-top: 0.25rem;">No duplicate company accounts detected.</p>
                    </div>
                @else
                    <div>
                        @foreach ($dupeCompanies as $group)
                            <div class="dq-dupe-group">
                                <div style="display: flex; align-items: center; gap: 0.5rem;">
                                    <span class="dq-badge-match">
                                        Matched on {{ $group['match_field'] }}: {{ $group['match_value'] }}
                                    </span>
                                    <span style="font-size: 0.75rem; color: #94a3b8;">({{ $group['companies']->count() }} records found)</span>
                                </div>

                                <div class="dq-dupe-grid">
                                    @php
                                        $primaryCompany = $group['companies']->first();
                                        $secondaryCompany = $group['companies']->last();
                                    @endphp
                                    @foreach ($group['companies'] as $company)
                                        <div class="dq-record-card {{ $loop->first ? 'primary-candidate' : '' }}">
                                            <div style="display: flex; align-items: center; justify-content: space-between;">
                                                <div style="font-weight: 700; font-size: 0.875rem;">
                                                    {{ $company->name }}
                                                    @if ($loop->first)
                                                        <span style="font-size: 0.625rem; font-weight: 700; background: #ffedd5; color: #c2410c; padding: 0.125rem 0.375rem; border-radius: 0.25rem; margin-left: 0.25rem;">
                                                            Primary Suggestion
                                                        </span>
                                                    @endif
                                                </div>
                                                <span style="color: #94a3b8; font-size: 0.6875rem;">ID #{{ $company->id }}</span>
                                            </div>
                                            <div style="margin-top: 0.5rem; display: flex; flex-direction: column; gap: 0.25rem;">
                                                <div>Domain: <strong>{{ $company->domain ?? '—' }}</strong></div>
                                                <div>Industry: <strong>{{ $company->industry ?? '—' }}</strong></div>
                                                <div>Health: <strong style="color: #10b981;">{{ $company->health_score }}/100</strong></div>
                                                <div>Tier: <strong style="text-transform: uppercase;">{{ $company->account_tier ?? 'Standard' }}</strong></div>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>

                                @if ($primaryCompany && $secondaryCompany && $primaryCompany->id !== $secondaryCompany->id)
                                    <div style="display: flex; justify-content: flex-end; padding-top: 0.25rem;">
                                        <button
                                            type="button"
                                            wire:click="mergeCompanies({{ $primaryCompany->id }}, {{ $secondaryCompany->id }})"
                                            wire:confirm="Merge {{ $secondaryCompany->name }} into {{ $primaryCompany->name }}? This will preserve all contacts and tickets."
                                            class="dq-btn-merge">
                                            <x-filament::icon icon="heroicon-m-arrows-right-left" style="width: 0.875rem; height: 0.875rem;" />
                                            Merge Duplicate into #{{ $primaryCompany->id }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
