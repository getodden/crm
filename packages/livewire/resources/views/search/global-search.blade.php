<div
    x-data="{
        open() { this.$dispatch('ui-modal-open', { name: 'global-search' }) },
        move(step) {
            const items = [this.$refs.field, ...this.$refs.results.querySelectorAll('a')]
            const next = Math.min(Math.max(items.indexOf(document.activeElement) + step, 0), items.length - 1)
            items[next].focus()
        }
    }"
    x-on:keydown.window.meta.k.prevent="open()"
    x-on:keydown.window.ctrl.k.prevent="open()"
>
    <x-ui::button variant="outline" size="sm" icon="fas-magnifying-glass" x-on:click="open()" aria-keyshortcuts="Control+K Meta+K">
        <span>Search</span>
        <kbd class="ms-2 hidden text-xs text-gray-600 sm:inline dark:text-gray-400" aria-hidden="true">Ctrl K</kbd>
    </x-ui::button>

    <x-ui::modal name="global-search" heading="Search" size="lg">
        <div x-on:keydown.arrow-down.prevent="move(1)" x-on:keydown.arrow-up.prevent="move(-1)">
            <x-ui::input
                type="search"
                label="Search"
                autocomplete="off"
                maxlength="{{ \Odden\Livewire\Search\GlobalSearch::MAX_LENGTH }}"
                x-ref="field"
                data-autofocus
                wire:model.live.debounce.300ms="search"
            />

            <div role="status" aria-live="polite" class="mt-3">
                @if ($searched)
                    @if ($count === 0)
                        <p class="text-sm text-gray-600 dark:text-gray-400">No results</p>
                    @else
                        <p class="sr-only">{{ $count }} {{ \Illuminate\Support\Str::plural('result', $count) }}</p>
                    @endif
                @endif
            </div>

            <div x-ref="results" class="mt-2 space-y-4">
                @foreach ($groups as $group)
                    <section aria-labelledby="global-search-group-{{ $loop->index }}">
                        <h3 id="global-search-group-{{ $loop->index }}" class="mb-1 text-xs font-semibold uppercase tracking-wide text-gray-600 dark:text-gray-400">{{ $group['label'] }}</h3>
                        <ul class="space-y-1">
                            @foreach ($group['hits'] as $hit)
                                <li>
                                    <a href="{{ $hit->url }}" class="block rounded-control px-3 py-2 text-sm hover:bg-gray-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-700 dark:hover:bg-gray-800 dark:focus-visible:outline-primary-400">
                                        <span class="block font-medium text-gray-900 dark:text-gray-100">{{ $hit->title }}</span>
                                        @foreach ($hit->details as $detail)
                                            <span class="block text-gray-600 dark:text-gray-400">{{ $detail }}</span>
                                        @endforeach
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                @endforeach
            </div>
        </div>
    </x-ui::modal>
</div>
