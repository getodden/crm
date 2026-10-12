<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\CompanyResource\Pages\ListCompanies;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Resources\SalesSequenceResource\Pages\ListSalesSequences;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

/**
 * The merge and enrol pickers reach every record (#114), and the contacts list sorts by name (#115).
 */
class PickerSearchAndSortTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_contact_beyond_the_first_page_can_be_found_and_merged(): void
    {
        $keep = Contact::factory()->create(['first_name' => 'Keep', 'last_name' => 'Aaa']);
        Contact::factory()->count(60)->sequence(fn ($s) => ['last_name' => 'B'.str_pad((string) $s->index, 3, '0', STR_PAD_LEFT)])->create();
        $duplicate = Contact::factory()->create(['first_name' => 'Zed', 'last_name' => 'Zzzimmer']);

        $this->actingAs(User::factory()->create());
        $this->assertArrayHasKey($duplicate->id, ContactResource::searchContactOptions('zzzim'));
        $this->assertArrayNotHasKey($keep->id, ContactResource::searchContactOptions('aaa', except: $keep));

        Livewire::test(ListContacts::class)
            ->callAction(TestAction::make('merge')->table($keep), ['secondary_contact_id' => $duplicate->id])
            ->assertHasNoFormErrors();

        $this->assertSoftDeleted($duplicate);
    }

    public function test_a_company_beyond_the_first_page_can_be_merged(): void
    {
        $keep = Company::factory()->create(['name' => 'Aaa Corp']);
        Company::factory()->count(60)->sequence(fn ($s) => ['name' => 'B'.str_pad((string) $s->index, 3, '0', STR_PAD_LEFT)])->create();
        $duplicate = Company::factory()->create(['name' => 'Zzz Corp']);

        Livewire::actingAs(User::factory()->create())
            ->test(ListCompanies::class)
            ->callAction(TestAction::make('merge')->table($keep), ['secondary_company_id' => $duplicate->id])
            ->assertHasNoFormErrors();

        $this->assertSoftDeleted($duplicate);
    }

    public function test_a_contact_beyond_the_first_hundred_can_be_enrolled(): void
    {
        Contact::factory()->count(105)->create();
        $late = Contact::factory()->create();
        $sequence = SalesSequence::query()->create([
            'name' => 'Outbound',
            'is_active' => true,
            'steps' => [['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Call']],
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(ListSalesSequences::class)
            ->callAction(TestAction::make('enrollContact')->table($sequence), ['contact_id' => $late->id])
            ->assertHasNoFormErrors();

        $this->assertTrue(SalesSequenceEnrollment::query()->where('contact_id', $late->id)->exists());
    }

    public function test_the_contacts_list_sorts_by_name(): void
    {
        $b = Contact::factory()->create(['first_name' => 'Ann', 'last_name' => 'Baker']);
        $a = Contact::factory()->create(['first_name' => 'Zoe', 'last_name' => 'Adams']);
        $c = Contact::factory()->create(['first_name' => 'Bob', 'last_name' => 'Clark']);

        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->sortTable('full_name')
            ->assertCanSeeTableRecords([$a, $b, $c], inOrder: true)
            ->sortTable('full_name', 'desc')
            ->assertCanSeeTableRecords([$c, $b, $a], inOrder: true);
    }
}
