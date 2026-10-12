<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Odden\Core\Contracts\MailRouteResolver;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Events\CompanyCreated;
use Odden\Core\Events\ContactCreated;
use Odden\Core\Events\LifecycleStageChanged;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\LifecycleStageTransition;
use Odden\Core\Models\PropertyDefinition;
use Odden\Filament\Imports\ContactImporter;
use Odden\Filament\Resources\CompanyResource\Pages\CreateCompany;
use Odden\Filament\Resources\CompanyResource\Pages\EditCompany;
use Odden\Filament\Resources\ContactResource\Pages\CreateContact;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\DealResource\Pages\EditDeal;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

/**
 * Lifecycle changes go through the state machine (#105), records are created through the create actions (#106),
 * and saving a form keeps custom properties it does not show (#107).
 */
class ContactAndCompanyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    // The factory's random phone numbers do not all pass the form's tel() format, which would fail a save.
    private const PHONE = '+1 555 0100';

    protected function setUp(): void
    {
        parent::setUp();

        // Offline: every domain accepts mail.
        $this->app->bind(MailRouteResolver::class, fn (): MailRouteResolver => new class implements MailRouteResolver
        {
            public function acceptsMail(string $domain): ?bool
            {
                return true;
            }
        });
    }

    public function test_changing_the_lifecycle_stage_on_the_edit_page_records_a_transition(): void
    {
        $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Lead, 'phone' => self::PHONE]);
        Event::fake([LifecycleStageChanged::class]);

        Livewire::actingAs(User::factory()->create())
            ->test(EditContact::class, ['record' => $contact->id])
            ->set('data.lifecycle_stage', LifecycleStage::SalesQualifiedLead->value)
            ->call('save')
            ->assertHasNoFormErrors();

        $contact->refresh();
        $this->assertSame(LifecycleStage::SalesQualifiedLead, $contact->lifecycle_stage);
        $this->assertNotNull($contact->became_sales_qualified_lead_at);
        $this->assertSame(1, LifecycleStageTransition::query()->where('record_id', $contact->id)->count());
        Event::assertDispatched(LifecycleStageChanged::class);
    }

    public function test_a_refused_lifecycle_transition_is_a_form_error_and_saves_nothing(): void
    {
        $contact = Contact::factory()->create(['lifecycle_stage' => LifecycleStage::Customer, 'job_title' => 'CTO', 'phone' => self::PHONE]);

        Livewire::actingAs(User::factory()->create())
            ->test(EditContact::class, ['record' => $contact->id])
            ->set('data.lifecycle_stage', LifecycleStage::Lead->value)
            ->set('data.job_title', 'CEO')
            ->call('save')
            ->assertHasFormErrors(['lifecycle_stage']);

        $contact->refresh();
        $this->assertSame(LifecycleStage::Customer, $contact->lifecycle_stage);
        $this->assertSame('CTO', $contact->job_title);
        $this->assertSame(0, LifecycleStageTransition::query()->count());
    }

    public function test_an_import_update_that_changes_the_stage_records_a_transition(): void
    {
        $contact = Contact::factory()->create(['email' => 'grace@navy.mil', 'lifecycle_stage' => LifecycleStage::Lead]);

        ContactImporter::test(options: ['existing' => 'update'])
            ->import(['email' => 'grace@navy.mil', 'lifecycle_stage' => 'opportunity'])
            ->assertImported();

        $contact->refresh();
        $this->assertSame(LifecycleStage::Opportunity, $contact->lifecycle_stage);
        $this->assertNotNull($contact->became_opportunity_at);
        $this->assertSame('import', LifecycleStageTransition::query()->where('record_id', $contact->id)->value('source'));
    }

    public function test_an_import_update_with_a_refused_transition_fails_the_row(): void
    {
        $contact = Contact::factory()->create(['email' => 'grace@navy.mil', 'first_name' => 'Grace', 'lifecycle_stage' => LifecycleStage::Customer]);

        ContactImporter::test(options: ['existing' => 'update'])
            ->import(['email' => 'grace@navy.mil', 'first_name' => 'Changed', 'lifecycle_stage' => 'lead'])
            ->assertHasRowFailure();

        $contact->refresh();
        $this->assertSame(LifecycleStage::Customer, $contact->lifecycle_stage);
        $this->assertSame('Grace', $contact->first_name);
    }

    public function test_an_imported_contact_announces_itself(): void
    {
        Event::fake([ContactCreated::class]);

        ContactImporter::test()->import(['email' => 'grace@navy.mil'])->assertImported();

        Event::assertDispatched(ContactCreated::class);
    }

    public function test_the_create_page_creates_a_contact_through_the_action(): void
    {
        Event::fake([ContactCreated::class]);

        Livewire::actingAs(User::factory()->create())
            ->test(CreateContact::class)
            ->fillForm(['email' => 'Grace@Navy.MIL', 'first_name' => 'Grace', 'lifecycle_stage' => LifecycleStage::Lead->value])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Contact::query()->where('email', 'grace@navy.mil')->exists());
        Event::assertDispatched(ContactCreated::class);
    }

    public function test_the_create_page_creates_a_company_through_the_action(): void
    {
        Event::fake([CompanyCreated::class]);

        Livewire::actingAs(User::factory()->create())
            ->test(CreateCompany::class)
            ->fillForm(['name' => 'Acme', 'domain' => 'https://Acme.com/'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame('acme.com', Company::query()->value('domain'));
        Event::assertDispatched(CompanyCreated::class);
    }

    public function test_saving_a_contact_keeps_properties_the_form_does_not_show(): void
    {
        PropertyDefinition::query()->create(['entity_type' => 'contact', 'name' => 'shoe_size', 'label' => 'Shoe size', 'type' => PropertyType::Text]);
        $contact = Contact::factory()->create(['properties' => ['shoe_size' => '9', 'playbook_budget' => '50k'], 'phone' => self::PHONE]);

        Livewire::actingAs(User::factory()->create())
            ->test(EditContact::class, ['record' => $contact->id])
            ->set('data.properties.shoe_size', '10')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['shoe_size' => '10', 'playbook_budget' => '50k'], $contact->refresh()->properties);
    }

    public function test_saving_a_company_or_deal_keeps_properties_the_form_does_not_show(): void
    {
        PropertyDefinition::query()->create(['entity_type' => 'company', 'name' => 'region', 'label' => 'Region', 'type' => PropertyType::Text]);
        PropertyDefinition::query()->create(['entity_type' => 'deal', 'name' => 'region', 'label' => 'Region', 'type' => PropertyType::Text]);
        $company = Company::factory()->create(['properties' => ['region' => 'EU', 'enriched_employees' => 120]]);
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id, 'properties' => ['region' => 'EU', 'meddic_champion' => 'Ana']]);
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(EditCompany::class, ['record' => $company->id])
            ->set('data.properties.region', 'US')
            ->call('save')
            ->assertHasNoFormErrors();

        Livewire::actingAs($user)
            ->test(EditDeal::class, ['record' => $deal->id])
            ->set('data.properties.region', 'US')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['region' => 'US', 'enriched_employees' => 120], $company->refresh()->properties);
        $this->assertSame(['region' => 'US', 'meddic_champion' => 'Ana'], $deal->refresh()->properties);
    }
}
