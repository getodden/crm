<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Livewire\Livewire;
use Odden\Core\Models\Contact;
use Odden\Core\Screens\Screens;
use Odden\Livewire\Pages\Contacts\ListContacts;
use Odden\Livewire\Tests\Fixtures\ConfigurablePolicy;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;

class RoutedScreensTest extends RoutedTestCase
{
    public function test_the_contact_list_url_is_the_livewire_route(): void
    {
        $this->assertTrue(Screens::available('contacts.index'));
        $this->assertSame(route('odden-livewire.contacts.index'), Screens::url('contacts.index'));
        $this->assertSame('/crm/contacts', Screens::url('contacts.index', absolute: false));
    }

    public function test_parameters_become_the_query_string(): void
    {
        $this->assertSame('/crm/contacts?tab=mine', Screens::url('contacts.index', parameters: ['tab' => 'mine'], absolute: false));
    }

    public function test_a_guest_is_sent_to_the_login_route(): void
    {
        $this->get('/crm/contacts')->assertRedirect(route('login'));
    }

    public function test_a_signed_in_user_sees_the_contacts(): void
    {
        Contact::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace', 'email' => 'ada@example.test']);

        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertOk()
            ->assertSee('Ada Lovelace')
            ->assertSee('ada@example.test')
            ->assertSee('<nav aria-label="Main"', false)
            ->assertSee('id="main"', false);
    }

    public function test_the_empty_state_is_shown_without_contacts(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertOk()
            ->assertSee('There are no contacts yet.');
    }

    public function test_the_list_is_newest_first_and_paginated_by_twenty_five(): void
    {
        Contact::factory()->count(26)->sequence(fn ($sequence): array => ['email' => "person{$sequence->index}@example.test"])->create();

        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertOk()
            ->assertSee('person25@example.test')
            ->assertDontSee('person0@example.test');
    }

    public function test_a_user_who_may_not_view_contacts_gets_a_forbidden_response(): void
    {
        $this->denyAbilities([Contact::class], ['viewAny']);

        $this->actingAs(User::factory()->create())->get('/crm/contacts')->assertForbidden();
    }

    public function test_a_livewire_update_is_authorized_again(): void
    {
        $this->denyAbilities([Contact::class], []);

        $component = Livewire::actingAs(User::factory()->create())->test(ListContacts::class)->assertOk();

        ConfigurablePolicy::$denied = ['viewAny'];

        $component->call('gotoPage', 2)->assertForbidden();
    }

    public function test_a_guest_cannot_mount_the_component(): void
    {
        Livewire::test(ListContacts::class)->assertForbidden();
    }

    public function test_the_navigation_is_left_out_for_guests(): void
    {
        $this->assertGuest();

        $html = view('odden-livewire::layouts.app', ['slot' => ''])->render();

        $this->assertStringNotContainsString('<nav', $html);
        $this->assertStringContainsString('href="#main"', $html);
    }
}
