<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\CompanyResource\Pages\ListCompanies;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Resources\DealResource\Pages\ViewDeal;
use Odden\Filament\Resources\SalesSequenceResource\Pages\ListSalesSequences;
use Odden\Filament\Support\OddenPackages;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\SalesSequence;

class ResourceActionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contact_actions_are_hidden_when_policy_denies_update(): void
    {
        $this->denyAbilities([Contact::class], ['update']);
        $contact = Contact::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->assertTableActionHidden('merge', $contact)
            ->assertTableActionHidden('run_playbook', $contact)
            ->assertTableActionHidden('route_lead', $contact)
            ->assertTableActionVisible('ai_briefing', $contact);
    }

    public function test_merge_is_not_offered_on_a_record_in_the_trash(): void
    {
        $live = Contact::factory()->create();
        $trashed = Contact::factory()->create();
        $trashed->delete();

        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->assertTableActionVisible('merge', $live)
            ->filterTable('trashed', true)
            ->assertTableActionHidden('merge', $trashed);

        $liveCompany = Company::factory()->create();
        $trashedCompany = Company::factory()->create();
        $trashedCompany->delete();

        Livewire::actingAs(User::factory()->create())
            ->test(ListCompanies::class)
            ->assertTableActionVisible('merge', $liveCompany)
            ->filterTable('trashed', true)
            ->assertTableActionHidden('merge', $trashedCompany);
    }

    public function test_contact_merge_cannot_delete_a_duplicate_the_policy_protects(): void
    {
        $this->denyAbilities([Contact::class], ['delete']);
        $primary = Contact::factory()->create();
        $secondary = Contact::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->callTableAction('merge', $primary, data: ['secondary_contact_id' => $secondary->id])
            ->assertForbidden();

        $this->assertNotNull(Contact::query()->find($secondary->id));
    }

    public function test_contact_merge_works_without_a_policy(): void
    {
        $primary = Contact::factory()->create();
        $secondary = Contact::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->callTableAction('merge', $primary, data: ['secondary_contact_id' => $secondary->id])
            ->assertHasNoTableActionErrors();

        $this->assertNull(Contact::query()->find($secondary->id));
    }

    public function test_sales_contact_actions_are_hidden_when_sales_is_not_installed(): void
    {
        OddenPackages::fake(['sales' => false]);
        $contact = Contact::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->assertTableActionHidden('run_playbook', $contact)
            ->assertTableActionHidden('route_lead', $contact)
            ->assertTableActionVisible('merge', $contact);
    }

    public function test_company_actions_are_hidden_when_policy_denies_update(): void
    {
        $this->denyAbilities([Company::class], ['update']);
        $company = Company::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(ListCompanies::class)
            ->assertTableActionHidden('merge', $company)
            ->assertTableActionHidden('recalculateHealth', $company);
    }

    public function test_mark_won_and_lost_are_hidden_when_policy_denies_update(): void
    {
        $this->denyAbilities([Deal::class], ['update']);
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'status' => DealStatus::Open,
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(ViewDeal::class, ['record' => $deal->getKey()])
            ->assertActionHidden('mark_won')
            ->assertActionHidden('mark_lost')
            ->assertActionHidden('run_playbook')
            ->assertActionHidden('generate_quote');
    }

    public function test_process_due_cadences_is_hidden_when_policy_denies_update(): void
    {
        $this->denyAbilities([SalesSequence::class], ['update']);
        SalesSequence::query()->create(['name' => 'Outreach', 'is_active' => true, 'steps' => []]);

        Livewire::actingAs(User::factory()->create())
            ->test(ListSalesSequences::class)
            ->assertActionHidden('processDueCadences');
    }

    public function test_custom_actions_are_visible_without_a_policy(): void
    {
        $contact = Contact::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->assertTableActionVisible('merge', $contact)
            ->assertTableActionVisible('run_playbook', $contact)
            ->assertTableActionVisible('route_lead', $contact);

        Livewire::actingAs(User::factory()->create())
            ->test(ListSalesSequences::class)
            ->assertActionVisible('processDueCadences');
    }
}
