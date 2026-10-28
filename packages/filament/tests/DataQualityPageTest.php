<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Tests\Fixtures\User;

class DataQualityPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_data_quality_command_center(): void
    {
        $user = User::factory()->create();

        // Create duplicate contacts
        Contact::factory()->create(['email' => 'duplicate@domain.com', 'first_name' => 'John', 'last_name' => 'Doe']);
        Contact::factory()->create(['email' => 'duplicate@domain.com', 'first_name' => 'Johnny', 'last_name' => 'Doe']);

        // Create duplicate companies
        Company::factory()->create(['name' => 'Omega Corp', 'domain' => 'omega.com']);
        Company::factory()->create(['name' => 'Omega Corporation', 'domain' => 'omega.com']);

        $response = $this->actingAs($user)->get('/admin/data-quality');

        $response->assertSuccessful();
        $response->assertSee('Data quality');
        $response->assertSee('Duplicates and missing details');
        $response->assertSee('duplicate@domain.com');
        $response->assertSee('Without duplicates')->assertDontSee('Guarantee');
    }

    public function test_can_merge_contacts_directly_from_data_quality_page(): void
    {
        $user = User::factory()->create();

        $c1 = Contact::factory()->create(['email' => 'merge.target@domain.com', 'lead_score' => 20]);
        $c2 = Contact::factory()->create(['email' => 'merge.target@domain.com', 'lead_score' => 80]);

        $component = Livewire::actingAs($user)
            ->test(DataQuality::class)
            ->call('mergeContacts', $c1->id, $c2->id);

        $component->assertHasNoErrors();

        expect($c1->fresh()->lead_score)->toBe(80)
            ->and($c2->fresh()->trashed())->toBeTrue();
    }
}
