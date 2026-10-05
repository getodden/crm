<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Action;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;
use Odden\Filament\Pages\ExecutiveOverview;
use Odden\Filament\Support\Modules;
use Odden\Filament\Tests\Fixtures\User;

class ModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_nothing_is_registered_by_default(): void
    {
        Modules::flush();

        $this->assertSame([], Modules::resources());
        $this->assertSame([], Modules::pages());
        $this->assertSame([], Modules::contactRelationManagers());
        $this->assertSame([], Modules::companyActions());
        $this->assertSame([], Modules::executiveCards());
        $this->assertFalse(Modules::has('marketing'));
    }

    public function test_a_module_adds_its_screens_and_pieces(): void
    {
        Modules::flush();
        Modules::register(
            'marketing',
            resources: ['A\\Resource'],
            pages: ['A\\Page'],
            contactRelationManagers: ['A\\Relations'],
            companyActions: [static fn (): Action => Action::make('demo')],
            authorizationResources: ['A\\Resource'],
        );
        Modules::register('service', resources: ['B\\Resource']);

        $this->assertTrue(Modules::has('marketing'));
        $this->assertSame(['A\\Resource', 'B\\Resource'], Modules::resources());
        $this->assertSame(['A\\Page'], Modules::pages());
        $this->assertSame(['A\\Relations'], Modules::contactRelationManagers());
        $this->assertSame('demo', Modules::companyActions()[0]->getName());
        $this->assertSame(['A\\Resource'], Modules::authorizationResources());
    }

    public function test_disabled_modules_leave_out_their_resources_and_pages(): void
    {
        Modules::flush();
        Modules::register('marketing', resources: ['A\\Resource'], pages: ['A\\Page']);
        Modules::register('service', resources: ['B\\Resource'], pages: ['B\\Page']);

        $this->assertSame(['B\\Resource'], Modules::resources(['marketing']));
        $this->assertSame(['A\\Page'], Modules::pages(['service']));
    }

    public function test_registering_a_module_again_replaces_it_instead_of_duplicating_it(): void
    {
        Modules::flush();
        Modules::register('marketing', resources: ['A\\Resource']);
        Modules::register('marketing', resources: ['A\\Resource']);

        $this->assertSame(['A\\Resource'], Modules::resources());
    }

    public function test_executive_cards_are_evaluated_when_asked_for_and_default_to_after_the_sales_card(): void
    {
        Modules::flush();
        Modules::register('marketing', executiveCards: [
            ['view' => 'demo::card', 'data' => static fn (): array => ['kpis' => ['n' => 3]], 'position' => 'before'],
            ['view' => 'demo::other', 'data' => static fn (): array => []],
        ]);

        $cards = Modules::executiveCards();

        $this->assertSame('before', $cards[0]['position']);
        $this->assertSame(['kpis' => ['n' => 3]], $cards[0]['data']);
        $this->assertSame('after', $cards[1]['position']);
    }

    public function test_the_executive_overview_hands_registered_cards_to_its_view(): void
    {
        View::addNamespace('demo', __DIR__.'/Fixtures/views');
        Modules::flush();
        Modules::register('marketing', executiveCards: [
            ['view' => 'demo::card', 'data' => static fn (): array => ['kpis' => ['n' => 3]]],
        ]);

        $component = Livewire::actingAs(User::factory()->create())->test(ExecutiveOverview::class);

        $this->assertSame('demo::card', $component->get('executiveCards')[0]['view']);
        $component->assertSee('Demo card: 3');
    }
}
