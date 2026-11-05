@if (config('odden-livewire.search.enabled', true) && auth()->check())
    <livewire:odden-livewire.global-search />
@endif
