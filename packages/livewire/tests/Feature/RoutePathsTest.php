<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Illuminate\Http\Request;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Screens\Screens;
use Odden\Livewire\Screens\LivewireScreens;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\Screens\Dashboard;
use Odden\Livewire\Tests\Fixtures\Screens\DisabledIndex;
use Odden\Livewire\Tests\Fixtures\Screens\Profile;
use Odden\Livewire\Tests\Fixtures\Screens\ThingsCreate;
use Odden\Livewire\Tests\Fixtures\Screens\ThingsEdit;
use Odden\Livewire\Tests\Fixtures\Screens\ThingsHistory;
use Odden\Livewire\Tests\Fixtures\Screens\ThingsImport;
use Odden\Livewire\Tests\Fixtures\Screens\ThingsIndex;
use Odden\Livewire\Tests\Fixtures\Screens\ThingsView;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class RoutePathsTest extends RoutedTestCase
{
    protected function defineThings(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(
                new Screen(key: 'things.view', label: 'Thing', record: true),
                new Screen(key: 'things.history', label: 'History', record: true),
                new Screen(key: 'things.edit', label: 'Edit', record: true),
                new Screen(key: 'things.create', label: 'Create'),
                new Screen(key: 'things.index', label: 'Things'),
                new Screen(key: 'things.import', label: 'Import'),
                new Screen(key: 'dashboard', label: 'Dashboard'),
                new Screen(key: 'profile', label: 'Profile', record: true),
                new Screen(key: 'disabled.index', label: 'Disabled', module: 'sales'),
            );

            foreach ([ThingsView::class, ThingsHistory::class, ThingsEdit::class, ThingsCreate::class, ThingsIndex::class, ThingsImport::class, Dashboard::class, Profile::class, DisabledIndex::class] as $component) {
                LivewireScreens::component($screens, $component::screenKey(), $component);
            }

            $screens->disableModules('sales');
        };
    }

    #[DefineEnvironment('defineThings')]
    public function test_each_page_kind_has_its_path(): void
    {
        $this->assertSame('/crm/things', Screens::url('things.index', absolute: false));
        $this->assertSame('/crm/things/create', Screens::url('things.create', absolute: false));
        $this->assertSame('/crm/things/import', Screens::url('things.import', absolute: false));
        $this->assertSame('/crm/things/7', Screens::url('things.view', 7, absolute: false));
        $this->assertSame('/crm/things/7/edit', Screens::url('things.edit', 7, absolute: false));
        $this->assertSame('/crm/things/7/history', Screens::url('things.history', 7, absolute: false));
        $this->assertSame('/crm/dashboard', Screens::url('dashboard', absolute: false));
        $this->assertSame('/crm/profile/7', Screens::url('profile', 7, absolute: false));
    }

    #[DefineEnvironment('defineThings')]
    public function test_a_record_route_does_not_shadow_a_fixed_page(): void
    {
        $router = app('router');

        $this->assertSame('odden-livewire.things.create', $router->getRoutes()->match(Request::create('/crm/things/create'))->getName());
        $this->assertSame('odden-livewire.things.import', $router->getRoutes()->match(Request::create('/crm/things/import'))->getName());
        $this->assertSame('odden-livewire.things.view', $router->getRoutes()->match(Request::create('/crm/things/42'))->getName());
    }

    #[DefineEnvironment('defineThings')]
    public function test_a_fixed_page_renders(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/crm/things/create')
            ->assertOk()
            ->assertSee('fixture-component');
    }

    #[DefineEnvironment('defineThings')]
    public function test_a_record_screen_needs_a_record_for_its_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Screens::url('things.view');
    }

    #[DefineEnvironment('defineThings')]
    public function test_a_screen_of_a_disabled_module_has_no_route(): void
    {
        $this->assertFalse(app('router')->has('odden-livewire.disabled.index'));
        $this->assertFalse(Screens::available('disabled.index'));
    }
}
