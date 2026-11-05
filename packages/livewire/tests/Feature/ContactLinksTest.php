<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Odden\Core\Models\Contact;
use Odden\Livewire\Tests\Fixtures\FixtureScreensServiceProvider;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;
use Orchestra\Testbench\Attributes\DefineEnvironment;

class ContactLinksTest extends RoutedTestCase
{
    protected function drawContactViewInAnotherShell(): void
    {
        FixtureScreensServiceProvider::drawInFixtureShell('contacts.view');
    }

    #[DefineEnvironment('drawContactViewInAnotherShell')]
    public function test_a_name_links_to_whichever_shell_draws_the_view_screen(): void
    {
        Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertOk()
            ->assertSee('href="https://fixture.test/contacts.view"', false)
            ->assertSee('Grace Hopper');
    }

    public function test_a_name_is_plain_text_when_no_shell_draws_the_view_screen(): void
    {
        Contact::factory()->create(['first_name' => 'Grace', 'last_name' => 'Hopper']);

        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertOk()
            ->assertSee('Grace Hopper')
            ->assertDontSee('contacts.view');
    }
}
