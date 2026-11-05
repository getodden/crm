<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Odden\Livewire\Pages\Contacts\ListContacts;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\Screens\ContactsIndex;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ReplacedScreensTest extends RoutedTestCase
{
    protected function replaceTheContactList(): void
    {
        FixtureScreensServiceProvider::$replacements = [ListContacts::class => ContactsIndex::class];
    }

    #[DefineEnvironment('replaceTheContactList')]
    public function test_the_route_serves_the_replacement(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertOk()
            ->assertSee('fixture-component');
    }
}
