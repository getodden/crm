@php
    $currentUrl = url()->current();
@endphp

<div x-data="{ open: false }" class="px-4 pb-4">
    <button
        type="button"
        class="rounded-control border border-gray-400 px-3 py-1.5 text-sm text-gray-900 hover:bg-gray-50 md:hidden dark:border-gray-500 dark:text-gray-100 dark:hover:bg-gray-800"
        aria-controls="main-navigation"
        aria-expanded="false"
        x-bind:aria-expanded="open.toString()"
        x-on:click="open = ! open"
    >Menu</button>

    <nav aria-label="Main" id="main-navigation" class="mt-2 hidden md:mt-0 md:block" x-bind:class="open ? '!block' : ''">
        @foreach (\Odden\Core\Screens\Screens::navigation(auth()->user()) as $section)
            <div class="mb-3">
                @if ($section['group'])
                    <h2 class="px-3 py-1 text-xs font-semibold uppercase text-gray-600 dark:text-gray-400">{{ $section['group'] }}</h2>
                @endif
                <ul>
                    @foreach ($section['screens'] as $screen)
                        @php
                            $url = \Odden\Core\Screens\Screens::url($screen->key);
                            $current = $url === $currentUrl;
                        @endphp
                        <li>
                            <a
                                href="{{ $url }}"
                                @if ($current) aria-current="page" @endif
                                class="flex items-center gap-2 rounded-control px-3 py-2 text-sm {{ $current ? 'bg-primary-50 font-semibold text-primary-700 dark:bg-gray-800 dark:text-primary-400' : 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800' }}"
                            >
                                @if ($screen->icon !== null)
                                    <x-ui::icon :name="$screen->icon" />
                                @endif
                                {{ $screen->label }}
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    </nav>
</div>
