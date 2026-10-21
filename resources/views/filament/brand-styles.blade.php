{{-- Brand rule: on dark, solid primary fills are violet-400 with dark text (white on violet-400 is only 2.7:1). Filament draws a fill at
     the same shade in both modes, so the dark pair is set here; the light-mode fill stays violet-700 with white text (the palette). --}}
<style>
    .dark .fi-btn.fi-color.fi-color-primary:not(label) {
        --dark-bg: var(--primary-400);
        --dark-hover-bg: var(--primary-300);
        --dark-text: #0b1020;
        --dark-hover-text: #0b1020;
    }
</style>
