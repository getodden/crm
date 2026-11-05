<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Core\Screens\Screens;
use Odden\Filament\Resources\ContactResource;
use Tests\TestCase;

class LivewireScreensTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_contact_list_is_drawn_by_livewire_and_the_rest_stay_with_filament(): void
    {
        $this->assertSame('/crm/contacts', Screens::url('contacts.index', absolute: false));
        $this->assertSame(ContactResource::getUrl('create'), Screens::url('contacts.create'));
        $this->assertStringContainsString('/admin/', Screens::url('contacts.create'));
    }

    public function test_a_signed_in_user_opens_the_livewire_contact_list(): void
    {
        Contact::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $this->actingAs(User::factory()->create())
            ->get('/crm/contacts')
            ->assertOk()
            ->assertSee('Ada Lovelace');
    }

    public function test_a_guest_is_sent_to_the_filament_login(): void
    {
        $this->get('/crm/contacts')->assertRedirect('/admin/login');
    }
}
