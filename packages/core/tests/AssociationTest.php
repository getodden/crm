<?php

declare(strict_types=1);

namespace Odden\Core\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Odden\Core\Actions\AssociateRecordsAction;
use Odden\Core\Events\RecordsAssociated;
use Odden\Core\Exceptions\InvalidAssociationException;
use Odden\Core\Exceptions\SystemAssociationTypeException;
use Odden\Core\Models\AssociationType;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

class AssociationTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_associate_contact_and_company_bidirectionally(): void
    {
        $contact = Contact::factory()->create();
        $company = Company::factory()->create();

        $this->assertFalse($contact->isAssociatedWith($company));

        $association = $contact->associateWith($company, 'primary');

        $this->assertNotNull($association);
        $this->assertTrue($contact->isAssociatedWith($company));
        $this->assertTrue($company->isAssociatedWith($contact));

        // Test bi-directional relationship fetching
        $contactCompanies = $contact->companies;
        $this->assertCount(1, $contactCompanies);
        $this->assertTrue($contactCompanies->first()->is($company));

        $companyContacts = $company->contacts;
        $this->assertCount(1, $companyContacts);
        $this->assertTrue($companyContacts->first()->is($contact));
    }

    public function test_associate_records_action_dispatches_event(): void
    {
        Event::fake([RecordsAssociated::class]);

        $contact = Contact::factory()->create();
        $company = Company::factory()->create();

        $action = new AssociateRecordsAction;
        $association = $action->execute($contact, $company, 'billing');

        $this->assertSame('billing', $association->type);

        Event::assertDispatched(RecordsAssociated::class);
    }

    public function test_can_dissociate_records(): void
    {
        $contact = Contact::factory()->create();
        $company = Company::factory()->create();

        $contact->associateWith($company, 'primary');
        $this->assertTrue($contact->isAssociatedWith($company));

        $contact->dissociateFrom($company);
        $this->assertFalse($contact->isAssociatedWith($company));
        $this->assertCount(0, $contact->companies);
    }

    public function test_association_checks_only_match_the_two_records(): void
    {
        $contact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        // Unrelated associations that share one side with the records being checked.
        $otherContact->associateWith($company);
        $contact->associateWith($otherCompany);

        $this->assertFalse($contact->isAssociatedWith($company));
        $this->assertFalse($company->isAssociatedWith($contact));

        $this->assertSame(0, $contact->dissociateFrom($company));
        $this->assertTrue($otherContact->isAssociatedWith($company));
        $this->assertTrue($contact->isAssociatedWith($otherCompany));

        $contact->associateWith($company);
        $this->assertSame(1, $company->dissociateFrom($contact));
        $this->assertTrue($otherContact->isAssociatedWith($company));
        $this->assertTrue($contact->isAssociatedWith($otherCompany));
    }

    public function test_plucking_ids_through_an_association_relation_is_not_ambiguous(): void
    {
        $contact = Contact::factory()->create();
        $company = Company::factory()->create();
        $contact->associateWith($company);

        $this->assertSame([$company->id], $contact->companies()->pluck('id')->all());
        $this->assertSame([$contact->id], $company->contacts()->pluck('id')->all());
        $this->assertSame([$company->name], $contact->companies()->pluck('name')->all());
    }

    public function test_association_type_record_types_are_enforced_in_either_order(): void
    {
        $type = AssociationType::create([
            'name' => 'employer',
            'label' => 'Employer',
            'from_record_type' => (new Company)->getMorphClass(),
            'to_record_type' => (new Contact)->getMorphClass(),
        ]);
        $contact = Contact::factory()->create();
        $otherContact = Contact::factory()->create();
        $company = Company::factory()->create();

        $this->assertNotNull($contact->associateWith($company, $type));
        $this->assertNotNull($company->associateWith($otherContact, $type));

        $this->expectException(InvalidAssociationException::class);
        $contact->associateWith($otherContact, $type);
    }

    public function test_a_type_with_only_one_record_type_requires_that_type_on_one_side(): void
    {
        $type = AssociationType::create(['name' => 'vendor', 'label' => 'Vendor', 'to_record_type' => (new Company)->getMorphClass()]);
        $contact = Contact::factory()->create();

        $this->assertNotNull($contact->associateWith(Company::factory()->create(), $type));

        $this->expectException(InvalidAssociationException::class);
        $contact->associateWith(Contact::factory()->create(), $type);
    }

    public function test_system_association_types_cannot_be_deleted_or_renamed(): void
    {
        $type = AssociationType::create(['name' => 'primary', 'label' => 'Primary', 'is_system' => true]);

        $type->update(['label' => 'Primary contact']);
        $this->assertSame('Primary contact', $type->fresh()->label);

        try {
            $type->update(['name' => 'renamed']);
            $this->fail('A system type was renamed.');
        } catch (SystemAssociationTypeException) {
            $this->assertSame('primary', $type->fresh()->name);
        }

        $this->expectException(SystemAssociationTypeException::class);
        $type->delete();
    }

    public function test_custom_association_types_can_be_deleted(): void
    {
        $type = AssociationType::create(['name' => 'custom', 'label' => 'Custom']);

        $this->assertTrue($type->delete());
    }
}
