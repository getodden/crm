<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Facades\Filament;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\Screens;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Pages\ExecutiveOverview;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Resources\DealResource;
use Odden\Filament\Screens\FilamentScreens;

class ScreensDrawnTest extends TestCase
{
    public function test_every_page_of_every_odden_resource_is_drawn_under_a_key(): void
    {
        $resources = array_filter(Filament::getPanel('admin')->getResources(), fn (string $class): bool => str_starts_with($class, 'Odden\\Filament\\Resources\\'));

        $this->assertNotEmpty($resources);

        foreach ($resources as $resource) {
            foreach (array_keys($resource::getPages()) as $page) {
                $drawn = array_filter(
                    Screens::keysDrawnBy(FilamentScreens::SHELL, $resource),
                    fn (string $key): bool => Screens::drawerFor($key, FilamentScreens::SHELL)?->page === $page,
                );

                $this->assertCount(1, $drawn, "{$resource} page [{$page}] is drawn by exactly one key");
            }
        }
    }

    public function test_every_odden_page_is_drawn_under_a_key(): void
    {
        foreach ([ExecutiveOverview::class, DataQuality::class, SalesCockpit::class] as $page) {
            $this->assertCount(1, Screens::keysDrawnBy(FilamentScreens::SHELL, $page), $page);
        }
    }

    public function test_every_defined_key_is_drawn_and_available(): void
    {
        foreach (Screens::all() as $screen) {
            $this->assertNotNull(Screens::drawerFor($screen->key, FilamentScreens::SHELL), $screen->key);
            $this->assertTrue(Screens::available($screen->key), $screen->key);
        }
    }

    public function test_navigation_matches_what_filament_shows(): void
    {
        $navigation = array_filter(Screens::all(), fn (Screen $screen): bool => $screen->navigation);

        $this->assertNotEmpty($navigation);

        foreach ($navigation as $screen) {
            $class = Screens::drawerFor($screen->key, FilamentScreens::SHELL)->class;

            $this->assertSame($screen->group, $class::getNavigationGroup(), "{$screen->key} group");
            $this->assertSame($screen->label, $class::getNavigationLabel(), "{$screen->key} label");
            $this->assertSame($screen->sort, $class::getNavigationSort(), "{$screen->key} sort");
        }
    }

    public function test_the_board_is_not_in_the_navigation_and_names_the_deal_board_page(): void
    {
        $drawer = Screens::drawerFor('deals.board', FilamentScreens::SHELL);

        $this->assertFalse(Screens::get('deals.board')->navigation);
        $this->assertSame(DealResource::class, $drawer->class);
        $this->assertSame('board', $drawer->page);
    }
}
