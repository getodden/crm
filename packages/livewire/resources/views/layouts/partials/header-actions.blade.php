@if (config('odden-livewire.search.enabled', true) && auth()->check())
    <livewire:odden-livewire.global-search />
@endif
@if (config('odden-livewire.bell.enabled', true) && auth()->check())
    <livewire:odden-livewire.notification-bell />
@endif
