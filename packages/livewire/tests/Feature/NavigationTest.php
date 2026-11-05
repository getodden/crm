<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Odden\Core\Models\Company;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Livewire\Screens\LivewireScreens;
use Odden\Livewire\Tests\Fixtures\FixtureComponent;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class NavigationTest extends RoutedTestCase
{
    protected function defineNavigationScreens(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(
                Screen::page('reports', 'Reports', viewAnyOf: [Company::class]),
                Screen::page('notes', 'Notes'),
            );
            LivewireScreens::component($screens, 'reports', FixtureComponent::class);
            LivewireScreens::component($screens, 'notes', FixtureComponent::class);
        };
    }

    #[DefineEnvironment('defineNavigationScreens')]
    public function test_a_screen_the_user_cannot_open_is_left_out_of_the_navigation(): void
    {
        $this->denyAbilities([Company::class], ['viewAny']);

        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertOk()
            ->assertSee('href="http://localhost/crm/notes"', false)
            ->assertDontSee('/crm/reports');
    }

    #[DefineEnvironment('defineNavigationScreens')]
    public function test_a_screen_the_user_can_open_is_in_the_navigation(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertSee('href="http://localhost/crm/reports"', false);
    }
}
