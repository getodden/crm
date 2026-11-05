<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Odden\Core\Exceptions\InvalidScreenRegistrationException;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenRegistry;
use Odden\Livewire\Pages\Contacts\ListContacts;
use Odden\Livewire\Screens\LivewireScreens;
use Odden\Livewire\Tests\Fixtures\FixtureComponent;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\PlainComponent;
use Odden\Livewire\Tests\TestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;
use stdClass;

class RouteGuardsTest extends TestCase
{
    protected function replaceWithANonComponent(): void
    {
        FixtureScreensServiceProvider::$replacements = [ListContacts::class => stdClass::class];
    }

    protected function replaceWithAComponentWithoutAuthorization(): void
    {
        FixtureScreensServiceProvider::$replacements = [ListContacts::class => PlainComponent::class];
    }

    protected function defineCollidingPaths(): void
    {
        FixtureScreensServiceProvider::$configure = function (ScreenRegistry $screens): void {
            $screens->define(new Screen(key: 'things', label: 'Things'), new Screen(key: 'things.index', label: 'Things'));
            LivewireScreens::component($screens, 'things', FixtureComponent::class);
            LivewireScreens::component($screens, 'things.index', FixtureComponent::class);
        };
    }

    #[DefineEnvironment('replaceWithANonComponent')]
    public function test_a_replacement_that_is_not_a_livewire_component_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('contacts.index');
        $this->expectExceptionMessage(stdClass::class);

        LivewireScreens::routes('crm');
    }

    #[DefineEnvironment('replaceWithAComponentWithoutAuthorization')]
    public function test_a_replacement_without_the_authorization_trait_is_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('contacts.index');
        $this->expectExceptionMessage(PlainComponent::class);

        LivewireScreens::routes('crm');
    }

    public function test_registering_the_routes_twice_is_refused(): void
    {
        LivewireScreens::routes('crm');

        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('odden-livewire.contacts.index');

        LivewireScreens::routes('crm');
    }

    #[DefineEnvironment('defineCollidingPaths')]
    public function test_two_screens_with_the_same_path_are_refused(): void
    {
        $this->expectException(InvalidScreenRegistrationException::class);
        $this->expectExceptionMessage('things');

        LivewireScreens::routes('crm');
    }
}
