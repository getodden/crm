<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Exceptions\ScreenNotAvailableException;
use Odden\Core\Exceptions\UnknownScreenException;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Core\Screens\Screens;
use Odden\Livewire\Pages\Contacts\ListContacts;
use Odden\Livewire\Screens\LivewireScreens;
use Odden\Livewire\Tests\Fixtures\FixtureComponent;
use Odden\Livewire\Tests\TestCase;
use stdClass;

class ScreenDrawingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_contact_list_is_drawn_by_the_livewire_shell(): void
    {
        $drawer = Screens::drawerFor('contacts.index', LivewireScreens::SHELL);

        $this->assertSame(ListContacts::class, $drawer?->class);
        $this->assertSame(LivewireScreens::SHELL, $drawer->shell);
    }

    public function test_without_routes_no_shell_draws_the_screen(): void
    {
        $this->assertFalse(Screens::available('contacts.index'));

        $this->expectException(ScreenNotAvailableException::class);

        Screens::url('contacts.index');
    }

    public function test_a_key_that_is_never_defined_fails_when_the_registry_is_frozen(): void
    {
        $registry = new ScreenRegistry;

        LivewireScreens::component($registry, 'nowhere.index', FixtureComponent::class);

        $this->expectException(UnknownScreenException::class);

        $registry->freeze();
    }

    public function test_a_class_that_is_not_a_livewire_component_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);

        LivewireScreens::component(new ScreenRegistry, 'contacts.index', stdClass::class);
    }
}
