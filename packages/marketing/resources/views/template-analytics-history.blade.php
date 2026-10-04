@php
    /** @var \Odden\Marketing\Models\MarketingTemplate|null $record */
    $record = $getRecord();
    
    $campaigns = $record ? $record->campaigns()->with('recipients')->get() : collect();
    $campaignCount = $campaigns->count();
    
    $totalSent = 0;
    $totalDelivered = 0;
    $totalOpens = 0;
    $totalClicks = 0;
    $totalBounces = 0;
    
    foreach ($campaigns as $camp) {
        $totalSent += $camp->sent_count ?? 0;
        $totalDelivered += $camp->delivered_count ?? 0;
        $totalOpens += $camp->open_count ?? 0;
        $totalClicks += $camp->click_count ?? 0;
        $totalBounces += $camp->bounce_count ?? 0;
    }
    
    $openRate = $totalDelivered > 0 ? round(($totalOpens / $totalDelivered) * 100, 1) : 0.0;
    $clickRate = $totalDelivered > 0 ? round(($totalClicks / $totalDelivered) * 100, 1) : 0.0;
    $revisions = $record ? $record->revisions()->with('creator')->latest()->take(8)->get() : collect();
@endphp

<div class="space-y-6">
    {{-- Key Attribution Metric Badges --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Total Campaigns</span>
            <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">
                {{ number_format($campaignCount) }}
            </div>
            <span class="text-xs text-slate-400 mt-1 inline-block">Active & historic broadcasts</span>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Total Delivered</span>
            <div class="mt-2 text-2xl font-bold text-slate-900 dark:text-slate-100">
                {{ number_format($totalDelivered) }}
            </div>
            <span class="text-xs text-slate-400 mt-1 inline-block">{{ number_format($totalSent) }} attempted</span>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Unique Open Rate</span>
            <div class="mt-2 text-2xl font-bold text-emerald-600 dark:text-emerald-400">
                {{ $openRate }}%
            </div>
            <span class="text-xs text-slate-400 mt-1 inline-block">{{ number_format($totalOpens) }} opens tracked</span>
        </div>

        <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-slate-800 dark:bg-slate-900">
            <span class="text-xs font-medium uppercase tracking-wider text-slate-500 dark:text-slate-400">Click-Through Rate</span>
            <div class="mt-2 text-2xl font-bold text-blue-600 dark:text-blue-400">
                {{ $clickRate }}%
            </div>
            <span class="text-xs text-slate-400 mt-1 inline-block">{{ number_format($totalClicks) }} links clicked</span>
        </div>
    </div>

    @if ($record && $record->hasAbTest())
        {{-- A/B Experiment Telemetry Card --}}
        <div class="rounded-xl border border-slate-200 bg-slate-50/50 p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900/60">
            <div class="flex items-center justify-between pb-3 border-b border-slate-200 dark:border-slate-800">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-sm text-slate-900 dark:text-slate-100">A/B Testing Status</span>
                    @if ($record->ab_winner_variant)
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300">
                            Winner: Variant {{ $record->ab_winner_variant }}
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300">
                            Experiment Live / In Progress
                        </span>
                    @endif
                </div>
                @if ($record->ab_completed_at)
                    <span class="text-xs text-slate-500">
                        Evaluated {{ $record->ab_completed_at->diffForHumans() }}
                    </span>
                @endif
            </div>

            <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                <div class="p-3 bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
                    <span class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Variant A (Default)</span>
                    <span class="text-slate-500 block truncate">Subject: {{ $record->subject }}</span>
                </div>
                <div class="p-3 bg-white dark:bg-slate-800 rounded-lg border border-slate-200 dark:border-slate-700">
                    <span class="font-bold text-slate-700 dark:text-slate-300 block mb-1">Variant B (Split)</span>
                    <span class="text-slate-500 block truncate">Subject: {{ $record->subject_variant_b ?? 'No variant subject' }}</span>
                </div>
            </div>
        </div>
    @endif

    {{-- Revision & Version History Table --}}
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900">
        <div class="flex items-center justify-between mb-4">
            <div>
                <h4 class="text-sm font-bold text-slate-900 dark:text-slate-100">Template Revision History</h4>
                <p class="text-xs text-slate-500 dark:text-slate-400">Automated immutable snapshots created on every template edit.</p>
            </div>
            <span class="text-xs font-medium text-slate-400">
                {{ $revisions->count() }} snapshot(s)
            </span>
        </div>

        @if ($revisions->isNotEmpty())
            <div class="divide-y divide-slate-100 dark:divide-slate-800 overflow-hidden">
                @foreach ($revisions as $rev)
                    <div class="py-3 flex items-center justify-between text-xs">
                        <div class="flex items-center gap-3">
                            <span class="font-mono px-2 py-0.5 bg-slate-100 dark:bg-slate-800 rounded font-semibold text-slate-700 dark:text-slate-300">
                                v{{ $rev->version_number }}
                            </span>
                            <div>
                                <span class="font-medium text-slate-900 dark:text-slate-100 block">
                                    {{ $rev->subject ?? 'Draft Template' }}
                                </span>
                                <span class="text-slate-400">
                                    {{ $rev->created_at ? $rev->created_at->diffForHumans() : 'Recently' }}
                                    @if ($rev->creator)
                                        by {{ $rev->creator->name ?? $rev->creator->email }}
                                    @endif
                                    @if (! empty($rev->change_summary))
                                        &bull; <span class="text-slate-600 dark:text-slate-300 italic">{{ $rev->change_summary }}</span>
                                    @endif
                                </span>
                            </div>
                        </div>

                        <div class="flex items-center gap-2">
                            <span class="font-mono text-slate-400">
                                {{ is_array($rev->slots) ? count($rev->slots) : 0 }} slots
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <p class="text-xs text-slate-400 italic py-2">No historical revisions recorded yet for this template.</p>
        @endif
    </div>
</div>
