<div class="flex flex-col gap-4 p-2">
    {{-- Score Header Banner --}}
    <div class="flex items-center justify-between p-4 rounded-xl border {{ $health['score'] >= 75 ? 'bg-emerald-50 border-emerald-200 dark:bg-emerald-950/30 dark:border-emerald-800' : ($health['score'] >= 50 ? 'bg-sky-50 border-sky-200 dark:bg-sky-950/30 dark:border-sky-800' : ($health['score'] >= 30 ? 'bg-amber-50 border-amber-200 dark:bg-amber-950/30 dark:border-amber-800' : 'bg-red-50 border-red-200 dark:bg-red-950/30 dark:border-red-800')) }}">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-full flex items-center justify-center font-extrabold text-lg {{ $health['score'] >= 75 ? 'bg-emerald-600 text-white' : ($health['score'] >= 50 ? 'bg-sky-600 text-white' : ($health['score'] >= 30 ? 'bg-amber-500 text-white' : 'bg-red-600 text-white')) }}">
                {{ $health['score'] }}
            </div>
            <div>
                <div class="text-xs font-bold uppercase tracking-wider text-slate-500">Deal Win Probability</div>
                <div class="text-base font-extrabold text-slate-900 dark:text-white">
                    {{ $health['badge_label'] }}
                </div>
            </div>
        </div>

        <div class="text-right text-xs text-slate-500">
            Multi-factor velocity engine
        </div>
    </div>

    {{-- Coaching Recommendations --}}
    @if (! empty($health['recommendations']))
        <div class="p-3.5 rounded-lg border border-amber-200 bg-amber-50/70 dark:bg-amber-950/30 dark:border-amber-800/80">
            <div class="flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-amber-800 dark:text-amber-300 mb-1.5">
                <x-filament::icon icon="heroicon-m-light-bulb" class="w-4 h-4 text-amber-600 dark:text-amber-400" />
                <span>Next Action Coaching Recommendations</span>
            </div>
            <ul class="list-disc list-inside space-y-1 text-xs text-amber-900 dark:text-amber-200 font-medium">
                @foreach ($health['recommendations'] as $rec)
                    <li>{{ $rec }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- Factors Breakdown --}}
    <div class="flex flex-col gap-2">
        <div class="text-xs font-bold uppercase tracking-wider text-slate-500 px-0.5">Factor Breakdown</div>
        @foreach ($health['factors'] as $factor)
            <div class="flex items-start gap-3 p-3 rounded-lg border {{ $factor['positive'] ? 'bg-white border-slate-200 dark:bg-slate-900 dark:border-slate-800' : 'bg-red-50/50 border-red-200 dark:bg-red-950/20 dark:border-red-900' }}">
                <div class="mt-0.5 shrink-0">
                    @if ($factor['positive'])
                        <x-filament::icon icon="heroicon-m-check-circle" class="w-5 h-5 text-emerald-500" />
                    @else
                        <x-filament::icon icon="heroicon-m-exclamation-triangle" class="w-5 h-5 text-red-500" />
                    @endif
                </div>

                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-900 dark:text-slate-100">{{ $factor['name'] }}</span>
                        <span class="text-[11px] font-bold px-2 py-0.5 rounded {{ $factor['positive'] ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' }}">
                            {{ $factor['points'] > 0 ? '+' : '' }}{{ $factor['points'] }} pts
                        </span>
                    </div>
                    <p class="text-xs text-slate-600 dark:text-slate-400 mt-0.5">
                        {{ $factor['description'] }}
                    </p>
                </div>
            </div>
        @endforeach
    </div>
</div>
