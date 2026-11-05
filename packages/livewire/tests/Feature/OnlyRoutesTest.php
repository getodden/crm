<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Livewire\Screens\LivewireScreens;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\Screens\Notes;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class OnlyRoutesTest extends RoutedTestCase
{
    protected function defineScreens(): void
    {
        FixtureScreensServiceProvider::$exceptions = ['excepted'];
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(
                Screen::page('notes', 'Notes'),
                Screen::page('excepted', 'Excepted'),
                Screen::page('filament.only', 'Filament only'),
            );
            LivewireScreens::component($screens, 'notes', Notes::class);
            LivewireScreens::component($screens, 'excepted', Notes::class);
        };
    }

    private function drawRoutesOnly(?array $only): void
    {
        app('router')->setRoutes(new RouteCollection);
        LivewireScreens::routes('crm', ['web'], $only);
        app('router')->getRoutes()->refreshNameLookups();
    }

    #[DefineEnvironment('defineScreens')]
    public function test_an_empty_list_registers_no_screen_routes(): void
    {
        $this->drawRoutesOnly([]);

        $this->assertFalse(Route::has('odden-livewire.contacts.index'));
        $this->assertFalse(Route::has('odden-livewire.notes'));
    }

    #[DefineEnvironment('defineScreens')]
    public function test_a_list_registers_just_its_keys(): void
    {
        $this->drawRoutesOnly(['contacts.index']);

        $this->assertTrue(Route::has('odden-livewire.contacts.index'));
        $this->assertFalse(Route::has('odden-livewire.notes'));
    }

    #[DefineEnvironment('defineScreens')]
    public function test_no_list_registers_every_drawn_screen(): void
    {
        $this->drawRoutesOnly(null);

        $this->assertTrue(Route::has('odden-livewire.contacts.index'));
        $this->assertTrue(Route::has('odden-livewire.notes'));
    }

    #[DefineEnvironment('defineScreens')]
    public function test_an_unknown_key_is_refused_by_name(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[contcats.index]');

        $this->drawRoutesOnly(['contcats.index']);
    }

    #[DefineEnvironment('defineScreens')]
    public function test_a_screen_the_livewire_shell_does_not_draw_is_refused_by_name(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[filament.only]');

        $this->drawRoutesOnly(['filament.only']);
    }

    #[DefineEnvironment('defineScreens')]
    public function test_an_excepted_screen_is_refused_by_name(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[excepted]');

        $this->drawRoutesOnly(['excepted']);
    }
}
