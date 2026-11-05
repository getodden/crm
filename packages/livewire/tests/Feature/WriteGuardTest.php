<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Livewire\Livewire;
use Odden\Livewire\Contracts\WriteGuard;
use Odden\Livewire\Support\AllowAllWrites;
use Odden\Livewire\Tests\Fixtures\GuardedComponent;
use Odden\Livewire\Tests\Fixtures\RefusingWriteGuard;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;

class WriteGuardTest extends RoutedTestCase
{
    private function refuseWrites(): void
    {
        $this->app->bind(WriteGuard::class, RefusingWriteGuard::class);
    }

    public function test_the_default_guard_allows_every_write(): void
    {
        $this->assertInstanceOf(AllowAllWrites::class, app(WriteGuard::class));

        Livewire::actingAs(User::factory()->create())
            ->test(GuardedComponent::class)
            ->call('bump')
            ->assertSet('count', 1)
            ->assertNotDispatched('ui-toast')
            ->call('askToBump')
            ->call('runConfirmation')
            ->assertSet('count', 2);
    }

    public function test_an_ordinary_method_is_refused_with_a_danger_toast(): void
    {
        $this->refuseWrites();

        Livewire::actingAs(User::factory()->create())
            ->test(GuardedComponent::class)
            ->call('bump')
            ->assertSet('count', 0)
            ->assertDispatched('ui-toast', message: RefusingWriteGuard::MESSAGE, variant: 'danger');
    }

    public function test_a_confirmed_action_is_refused(): void
    {
        $component = Livewire::actingAs(User::factory()->create())
            ->test(GuardedComponent::class)
            ->call('askToBump');

        $this->refuseWrites();

        $component
            ->call('runConfirmation')
            ->assertSet('count', 0)
            ->assertDispatched('ui-toast', variant: 'danger');
    }

    public function test_reads_pagination_refresh_and_property_updates_still_run(): void
    {
        $this->refuseWrites();

        Livewire::actingAs(User::factory()->create())
            ->test(GuardedComponent::class)
            ->call('peek')
            ->assertSet('name', 'peeked')
            ->call('gotoPage', 2)
            ->assertSet('paginators.page', 2)
            ->call('nextPage')
            ->assertSet('paginators.page', 3)
            ->call('cancelConfirmation')
            ->call('$refresh')
            ->set('name', 'typed')
            ->assertSet('name', 'typed')
            ->assertNotDispatched('ui-toast');
    }

    public function test_an_event_listener_that_writes_is_refused_and_one_that_reads_runs(): void
    {
        $this->refuseWrites();

        $component = Livewire::actingAs(User::factory()->create())->test(GuardedComponent::class);

        $component->dispatch('look')->assertSet('name', 'looked');

        $component->dispatch('poke')->assertStatus(403)->assertSet('count', 0);
    }

    public function test_the_guard_is_asked_on_every_call(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(GuardedComponent::class);

        $component->call('bump')->assertSet('count', 1);

        $this->refuseWrites();

        $component->call('bump')->assertSet('count', 1);
    }
}
