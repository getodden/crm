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
use Odden\Livewire\Tests\Fixtures\Screens\Things;
use Odden\Livewire\Tests\Fixtures\Screens\ThingsView;
use Odden\Livewire\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ExplicitPathGuardsTest extends TestCase
{
    protected function drawRoutes(?array $only = null): void
    {
        app('router')->setRoutes(new RouteCollection);
        LivewireScreens::routes('crm', ['web'], $only);
        app('router')->getRoutes()->refreshNameLookups();
    }

    protected function defineSamePathTwice(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(Screen::page('notes', 'Notes'), Screen::page('things', 'Things'));
            LivewireScreens::component($screens, 'notes', Notes::class, path: 'shared');
            LivewireScreens::component($screens, 'things', Things::class, path: 'shared');
        };
    }

    protected function definePathMatchingAKeyDerivedPath(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(Screen::page('notes', 'Notes'), Screen::page('things', 'Things'));
            LivewireScreens::component($screens, 'notes', Notes::class, path: 'things');
            LivewireScreens::component($screens, 'things', Things::class);
        };
    }

    protected function defineRecordPathWithoutRecord(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(new Screen(key: 'things.view', label: 'Thing', record: true));
            LivewireScreens::component($screens, 'things.view', ThingsView::class, path: 'thing');
        };
    }

    protected function defineEmptyPath(): void
    {
        $this->defineNotesAt('');
    }

    protected function defineLeadingSlashPath(): void
    {
        $this->defineNotesAt('/team-notes');
    }

    protected function defineTrailingSlashPath(): void
    {
        $this->defineNotesAt('team-notes/');
    }

    protected function defineNotesAt(string $path): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens) use ($path): void {
            $screens->define(Screen::page('notes', 'Notes'));
            LivewireScreens::component($screens, 'notes', Notes::class, path: $path);
        };
    }

    protected function defineExplicitPath(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(Screen::page('notes', 'Notes'), Screen::page('things', 'Things'));
            LivewireScreens::component($screens, 'notes', Notes::class, path: 'team-notes');
            LivewireScreens::component($screens, 'things', Things::class);
        };
    }

    #[DefineEnvironment('defineSamePathTwice')]
    public function test_two_screens_claiming_the_same_explicit_path_are_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[shared]');

        $this->drawRoutes();
    }

    #[DefineEnvironment('definePathMatchingAKeyDerivedPath')]
    public function test_an_explicit_path_colliding_with_a_derived_path_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[things]');

        $this->drawRoutes();
    }

    #[DefineEnvironment('defineEmptyPath')]
    public function test_an_explicit_path_that_is_empty_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[notes]');
        $this->expectExceptionMessage('[]');

        $this->drawRoutes();
    }

    #[DefineEnvironment('defineLeadingSlashPath')]
    public function test_an_explicit_path_that_is_leading_slash_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[notes]');
        $this->expectExceptionMessage('[/team-notes]');

        $this->drawRoutes();
    }

    #[DefineEnvironment('defineTrailingSlashPath')]
    public function test_an_explicit_path_that_is_trailing_slash_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[notes]');
        $this->expectExceptionMessage('[team-notes/]');

        $this->drawRoutes();
    }

    #[DefineEnvironment('defineRecordPathWithoutRecord')]
    public function test_a_record_screen_path_without_the_record_placeholder_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('[things.view]');
        $this->expectExceptionMessage('{record}');

        $this->drawRoutes();
    }

    #[DefineEnvironment('defineExplicitPath')]
    public function test_a_screen_with_an_explicit_path_that_is_not_listed_gets_no_route(): void
    {
        $this->drawRoutes(['things']);

        $this->assertFalse(Route::has('odden-livewire.notes'));
        $this->assertTrue(Route::has('odden-livewire.things'));
    }
}
