<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @livewireStyles
</head>
<body class="bg-gray-50 text-gray-900 dark:bg-gray-950 dark:text-gray-100">
    <a href="#main" class="sr-only focus:not-sr-only">Skip to content</a>

    @auth
        <nav aria-label="Main" class="border-b border-gray-200 p-4 dark:border-gray-800">
            @foreach (\Odden\Core\Screens\Screens::navigation(auth()->user()) as $section)
                <div class="mb-2">
                    @if ($section['group'])
                        <h2 class="text-xs font-semibold uppercase text-gray-600 dark:text-gray-400">{{ $section['group'] }}</h2>
                    @endif
                    <ul class="flex flex-wrap gap-4">
                        @foreach ($section['screens'] as $screen)
                            <li><a href="{{ \Odden\Core\Screens\Screens::url($screen->key) }}" class="text-primary-700 dark:text-primary-400">{{ $screen->label }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </nav>
    @endauth

    <main id="main" class="mx-auto max-w-5xl p-4">
        {{ $slot }}
    </main>

    <x-ui::toasts />
    @livewireScripts
</body>
</html>
