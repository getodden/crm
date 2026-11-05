<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' - ' : '' }}{{ config('app.name') }}</title>
    <script @if (\Illuminate\Support\Facades\Vite::cspNonce()) nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}" @endif>
        (() => {
            let theme = 'system'
            try {
                theme = localStorage.getItem('theme') ?? 'system'
            } catch (error) {}
            const dark = theme === 'dark' || (theme !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches)
            document.documentElement.classList.toggle('dark', dark)
        })()
    </script>
    @include('odden-livewire::layouts.partials.head')
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
    <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:start-2 focus:top-2 focus:z-50 focus:rounded-control focus:bg-white focus:px-3 focus:py-2 focus:text-primary-700 dark:focus:bg-gray-900 dark:focus:text-primary-400">Skip to content</a>

    <div class="min-h-screen md:flex">
        <aside class="border-b border-gray-200 bg-white md:w-64 md:shrink-0 md:border-e md:border-b-0 dark:border-gray-800 dark:bg-gray-900">
            <div class="p-4">
                @include('odden-livewire::layouts.partials.brand')
            </div>
            @auth
                @include('odden-livewire::layouts.partials.navigation')
            @endauth
        </aside>

        <div class="min-w-0 flex-1">
            <header class="flex items-center justify-end gap-3 border-b border-gray-200 bg-white px-4 py-3 dark:border-gray-800 dark:bg-gray-900">
                @include('odden-livewire::layouts.partials.header-actions')
                @auth
                    @include('odden-livewire::layouts.partials.user-menu')
                @endauth
            </header>

            @include('odden-livewire::layouts.partials.before-content')

            <main id="main" class="mx-auto max-w-5xl p-4">
                {{ $slot }}
            </main>
        </div>
    </div>

    <x-ui::toasts />
    @include('odden-livewire::layouts.partials.body-end')
    @livewireScripts
</body>
</html>
