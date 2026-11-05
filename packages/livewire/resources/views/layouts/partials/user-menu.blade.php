<div class="flex items-center gap-3">
    <span class="text-sm text-gray-700 dark:text-gray-300">{{ auth()->user()->name ?? auth()->user()->email ?? '' }}</span>

    @if (\Illuminate\Support\Facades\Route::has(config('odden-livewire.logout_route', 'logout')))
        <form method="POST" action="{{ route(config('odden-livewire.logout_route', 'logout')) }}">
            @csrf
            <x-ui::button type="submit" variant="ghost" size="sm">Sign out</x-ui::button>
        </form>
    @endif
</div>
