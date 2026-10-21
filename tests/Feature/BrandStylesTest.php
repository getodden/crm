<?php

declare(strict_types=1);

/**
 * Brand rule: on dark, solid primary fills are violet-400 with dark text. Filament draws a fill at the same shade in both modes, so
 * resources/views/filament/brand-styles.blade.php sets the dark pair; without it dark buttons are violet-700 on a near-black page.
 */
it('sets the dark-mode primary button colours on the admin panel', function (): void {
    $this->get('/admin/login')
        ->assertOk()
        ->assertSee('--dark-bg: var(--primary-400)', false)
        ->assertSee('--dark-text: #0b1020', false);
});
