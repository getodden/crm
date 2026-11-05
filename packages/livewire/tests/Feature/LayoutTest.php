<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Livewire\Screens\LivewireScreens;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\Screens\Notes;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class LayoutTest extends RoutedTestCase
{
    protected function defineNotes(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(Screen::page('notes', 'Notes', icon: 'fas-note-sticky'));
            LivewireScreens::component($screens, 'notes', Notes::class);
        };
    }

    #[DefineEnvironment('defineNotes')]
    public function test_the_current_screen_link_is_marked_once(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/crm/notes')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a\s+href="http://localhost/crm/notes"\s+aria-current="page"#', $html);
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    #[DefineEnvironment('defineNotes')]
    public function test_the_landmarks_the_skip_link_and_one_toast_region_are_present(): void
    {
        $html = $this->actingAs(User::factory()->create())->get('/crm/notes')->getContent();

        $this->assertStringContainsString('href="#main"', $html);
        $this->assertStringContainsString('<main id="main"', $html);
        $this->assertStringContainsString('aria-label="Main"', $html);
        $this->assertStringContainsString('aria-controls="main-navigation"', $html);
        $this->assertSame(1, substr_count($html, 'ui-toast.window'));
        $this->assertStringContainsString('localStorage.getItem(\'theme\')', $html);
    }

    #[DefineEnvironment('defineNotes')]
    public function test_the_brand_is_the_app_name(): void
    {
        config(['app.name' => 'Acme Desk']);

        $this->actingAs(User::factory()->create())->get('/crm/notes')->assertSee('Acme Desk');
    }

    #[DefineEnvironment('defineNotes')]
    public function test_a_host_partial_replaces_the_package_partial(): void
    {
        $directory = sys_get_temp_dir().'/odden-livewire-views-'.bin2hex(random_bytes(4));
        mkdir($directory.'/layouts/partials', 0777, true);
        file_put_contents($directory.'/layouts/partials/head.blade.php', '<meta name="host-head" content="1">');
        file_put_contents($directory.'/layouts/partials/before-content.blade.php', '<div>host-banner</div>');
        View::prependNamespace('odden-livewire', $directory);

        $this->actingAs(User::factory()->create())
            ->get('/crm/notes')
            ->assertSee('<meta name="host-head" content="1">', false)
            ->assertSee('host-banner');
    }

    #[DefineEnvironment('defineNotes')]
    public function test_the_sign_out_form_appears_only_when_the_route_exists(): void
    {
        $this->actingAs(User::factory()->create())->get('/crm/notes')->assertDontSee('Sign out');

        Route::post('/logout', fn (): string => 'out')->name('logout');
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs(User::factory()->create())
            ->get('/crm/notes')
            ->assertSee('Sign out')
            ->assertSee('action="http://localhost/logout"', false);
    }

    #[DefineEnvironment('defineNotes')]
    public function test_the_sign_out_route_name_is_configurable(): void
    {
        config(['odden-livewire.logout_route' => 'auth.leave']);
        Route::post('/leave', fn (): string => 'out')->name('auth.leave');
        Route::getRoutes()->refreshNameLookups();

        $this->actingAs(User::factory()->create())
            ->get('/crm/notes')
            ->assertSee('action="http://localhost/leave"', false);
    }

    #[DefineEnvironment('defineNotes')]
    public function test_an_icon_is_drawn_only_when_the_screen_has_one(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/crm/notes')
            ->assertSee('<svg', false);
    }

    #[DefineEnvironment('defineNotes')]
    public function test_the_theme_script_carries_the_csp_nonce(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/crm/notes')
            ->assertDontSee('<script nonce=', false);

        Vite::useCspNonce('abc123');

        $this->actingAs(User::factory()->create())
            ->get('/crm/notes')
            ->assertSee('nonce="abc123"', false);
    }
}
