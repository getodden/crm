<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Odden\Core\Enums\AssociationCardinality;
use Odden\Core\Events\RecordsAssociated;
use Odden\Core\Models\Association;
use Odden\Core\Models\AssociationType;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\CompanyResource\Pages\EditCompany;
use Odden\Filament\Resources\CompanyResource\RelationManagers\ContactsRelationManager;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\ContactResource\RelationManagers\CompaniesRelationManager;
use Odden\Filament\Tests\Fixtures\User;

class AttachThroughAssociationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_attaching_a_company_from_a_contact_goes_through_the_association_action(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $company = Company::factory()->create();
        Event::fake([RecordsAssociated::class]);

        Livewire::actingAs($user)
            ->test(CompaniesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
            ->callAction(TestAction::make('attach')->table(), ['recordId' => $company->id, 'type' => 'billing'])
            ->assertHasNoFormErrors();

        $association = Association::query()->where('child_id', $company->id)->firstOrFail();
        $this->assertSame($contact->getMorphClass(), $association->parent_type);
        $this->assertSame($contact->id, $association->parent_id);
        $this->assertSame($company->getMorphClass(), $association->child_type);
        $this->assertSame('billing', $association->type);
        Event::assertDispatched(RecordsAssociated::class);
        $this->assertCount(1, $contact->getAssociated(Company::class), 'The CRM reads the link back through the association model');
    }

    public function test_attaching_a_contact_from_a_company_keeps_the_contact_as_the_parent(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        $contact = Contact::factory()->create();

        Livewire::actingAs($user)
            ->test(ContactsRelationManager::class, ['ownerRecord' => $company, 'pageClass' => EditCompany::class])
            ->callAction(TestAction::make('attach')->table(), ['recordId' => $contact->id, 'type' => 'primary']);

        $association = Association::query()->firstOrFail();
        $this->assertSame($contact->getMorphClass(), $association->parent_type);
        $this->assertSame($company->getMorphClass(), $association->child_type);
    }

    public function test_a_link_that_breaks_a_cardinality_rule_is_refused_with_a_message(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $first = Company::factory()->create();
        $second = Company::factory()->create();
        AssociationType::create(['name' => 'billing', 'label' => 'Billing entity', 'cardinality' => AssociationCardinality::OneToOne]);

        $attach = fn (Company $company) => Livewire::actingAs($user)
            ->test(CompaniesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
            ->callAction(TestAction::make('attach')->table(), ['recordId' => $company->id, 'type' => 'billing']);

        $attach($first);
        $attach($second);

        $this->assertSame(1, Association::query()->where('type', 'billing')->count(), 'The second link broke the one-to-one rule');
    }
}
