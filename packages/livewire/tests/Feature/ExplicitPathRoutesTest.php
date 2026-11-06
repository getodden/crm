<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Screens\Screens;
use Odden\Livewire\Screens\LivewireScreens;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\Screens\Notes;
use Odden\Livewire\Tests\Fixtures\Screens\Things;
use Odden\Livewire\Tests\Fixtures\Screens\ThingsView;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ExplicitPathRoutesTest extends RoutedTestCase
{
    protected function defineExplicitPath(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(
                Screen::page('notes', 'Notes'),
                Screen::page('things', 'Things'),
            );
            LivewireScreens::component($screens, 'notes', Notes::class, path: 'team-notes');
            LivewireScreens::component($screens, 'things', Things::class);
        };
    }

    protected function defineRecordPathWithRecord(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(new Screen(key: 'things.view', label: 'Thing', record: true));
            LivewireScreens::component($screens, 'things.view', ThingsView::class, path: 'thing/{record}');
        };
    }

    #[DefineEnvironment('defineExplicitPath')]
    public function test_an_explicit_path_is_served_and_resolved_by_url(): void
    {
        $this->assertSame('/crm/team-notes', Screens::url('notes', absolute: false));

        $this->actingAs(User::factory()->create())
            ->get('/crm/team-notes')
            ->assertOk()
            ->assertSee('fixture-component');
    }

    #[DefineEnvironment('defineExplicitPath')]
    public function test_a_screen_without_an_explicit_path_keeps_its_key_derived_path(): void
    {
        $this->assertSame('/crm/things', Screens::url('things', absolute: false));
    }

    #[DefineEnvironment('defineRecordPathWithRecord')]
    public function test_the_record_placeholder_in_an_explicit_path_is_filled_when_the_url_is_generated(): void
    {
        $this->assertSame('/crm/thing/7', Screens::url('things.view', 7, absolute: false));
    }
}
