<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Imports\Models\FailedImportRow;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Odden\Core\Contracts\MailRouteResolver;
use Odden\Core\Enums\EmailStatus;
use Odden\Core\Enums\EmailStatusReason;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Filament\Imports\ContactImporter;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Tests\Fixtures\User;

class ContactImporterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Offline by default: a domain ending in .invalid has no mail server, everything else does.
        $this->app->bind(MailRouteResolver::class, fn (): MailRouteResolver => new class implements MailRouteResolver
        {
            public function acceptsMail(string $domain): ?bool
            {
                return ! str_ends_with($domain, '.invalid');
            }
        });
    }

    public function test_it_imports_a_contact_and_records_a_valid_status(): void
    {
        ContactImporter::test()
            ->import(['email' => 'Grace@Navy.mil', 'first_name' => 'Grace', 'last_name' => 'Hopper'])
            ->assertImported();

        $contact = Contact::query()->where('email', 'grace@navy.mil')->firstOrFail();

        $this->assertSame('Grace', $contact->first_name);
        $this->assertSame(EmailStatus::Valid, $contact->email_status);
        $this->assertNotNull($contact->email_checked_at);
    }

    public function test_it_imports_a_date_of_birth_and_rejects_one_in_the_future(): void
    {
        ContactImporter::test()
            ->import(['email' => 'scout@troop12.org', 'date_of_birth' => '2012-04-09'])
            ->assertImported();

        $this->assertSame('2012-04-09', Contact::query()->where('email', 'scout@troop12.org')->firstOrFail()->date_of_birth->toDateString());

        ContactImporter::test()
            ->import(['email' => 'future@troop12.org', 'date_of_birth' => now()->addYear()->toDateString()])
            ->assertHasErrors(['date_of_birth']);

        $this->assertSame(1, Contact::query()->count());
    }

    public function test_it_rejects_a_malformed_address_with_the_reason(): void
    {
        ContactImporter::test()
            ->import(['email' => 'not-an-email'])
            ->assertHasRowFailure('Not a valid email address');

        $this->assertSame(0, Contact::query()->count());
    }

    public function test_it_rejects_a_domain_that_accepts_no_mail(): void
    {
        ContactImporter::test()
            ->import(['email' => 'grace@gone.invalid'])
            ->assertHasRowFailure('Domain does not accept email');

        $this->assertSame(0, Contact::query()->count());
    }

    public function test_it_imports_risky_addresses_and_flags_them(): void
    {
        ContactImporter::test()->import(['email' => 'info@acme.com'])->assertImported();
        ContactImporter::test()->import(['email' => 'grace@gmial.com'])->assertImported();

        $role = Contact::query()->where('email', 'info@acme.com')->firstOrFail();
        $typo = Contact::query()->where('email', 'grace@gmial.com')->firstOrFail();

        $this->assertSame(EmailStatus::Risky, $role->email_status);
        $this->assertSame(EmailStatusReason::Role, $role->email_status_reason);
        $this->assertSame(EmailStatusReason::Typo, $typo->email_status_reason);
    }

    public function test_it_leaves_the_dns_step_to_configuration(): void
    {
        config(['odden-core.email_validation.check_dns' => false]);

        ContactImporter::test()->import(['email' => 'grace@navy.mil'])->assertImported();

        $contact = Contact::query()->firstOrFail();
        $this->assertSame(EmailStatus::Unknown, $contact->email_status);
        $this->assertSame(EmailStatusReason::NotChecked, $contact->email_status_reason);
    }

    public function test_it_does_not_validate_when_email_validation_is_disabled(): void
    {
        config(['odden-core.email_validation.enabled' => false]);

        ContactImporter::test()->import(['email' => 'grace@gone.invalid'])->assertImported();

        $this->assertNull(Contact::query()->firstOrFail()->email_status);
    }

    public function test_it_skips_an_existing_contact_by_default_and_says_why(): void
    {
        Contact::factory()->create(['email' => 'grace@navy.mil', 'first_name' => 'Original']);

        ContactImporter::test()
            ->import(['email' => 'GRACE@navy.mil', 'first_name' => 'Changed'])
            ->assertHasRowFailure('A contact with this email address already exists.');

        $this->assertSame('Original', Contact::query()->firstOrFail()->first_name);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_it_updates_an_existing_contact_when_asked_to(): void
    {
        $existing = Contact::factory()->create(['email' => 'grace@navy.mil', 'first_name' => 'Original', 'phone' => '555']);

        ContactImporter::test(options: ['existing' => 'update'])
            ->import(['email' => 'grace@navy.mil', 'first_name' => 'Changed'])
            ->assertImported();

        $existing->refresh();

        $this->assertSame('Changed', $existing->first_name);
        $this->assertSame('555', $existing->phone);
        $this->assertSame(1, Contact::query()->count());
        $this->assertSame(EmailStatus::Valid, $existing->email_status);
    }

    public function test_a_blank_cell_does_not_wipe_a_stored_value_on_update(): void
    {
        $existing = Contact::factory()->create([
            'email' => 'grace@navy.mil',
            'first_name' => 'Grace',
            'phone' => '555',
            'lifecycle_stage' => LifecycleStage::Customer,
        ]);

        ContactImporter::test(options: ['existing' => 'update'])
            ->import(['email' => 'grace@navy.mil', 'first_name' => '', 'phone' => '', 'lifecycle_stage' => '', 'last_name' => 'Hopper'])
            ->assertImported();

        $existing->refresh();

        $this->assertSame('Grace', $existing->first_name);
        $this->assertSame('555', $existing->phone);
        $this->assertSame(LifecycleStage::Customer, $existing->lifecycle_stage);
        $this->assertSame('Hopper', $existing->last_name);
    }

    public function test_updating_a_bounced_contact_keeps_its_bounced_status(): void
    {
        $existing = Contact::factory()->create(['email' => 'grace@navy.mil', 'first_name' => 'Original']);
        $existing->forceFill(['email_status' => EmailStatus::Invalid, 'email_status_reason' => EmailStatusReason::Bounced])->saveQuietly();

        ContactImporter::test(options: ['existing' => 'update'])
            ->import(['email' => 'grace@navy.mil', 'first_name' => 'Changed'])
            ->assertImported();

        $existing->refresh();

        $this->assertSame('Changed', $existing->first_name);
        $this->assertTrue($existing->hasBounced());
    }

    public function test_a_duplicate_within_one_file_is_reported_not_created_twice(): void
    {
        $importer = ContactImporter::test();

        $importer->import(['email' => 'grace@navy.mil'])->assertImported();
        $importer->import(['email' => 'grace@navy.mil'])->assertHasRowFailure('A contact with this email address already exists.');

        $this->assertSame(1, Contact::query()->count());
    }

    public function test_it_accepts_stage_and_status_by_label_or_value(): void
    {
        ContactImporter::test()
            ->import(['email' => 'grace@navy.mil', 'lifecycle_stage' => 'Customer', 'lead_status' => 'In Progress'])
            ->assertImported();

        $contact = Contact::query()->firstOrFail();

        $this->assertSame(LifecycleStage::Customer, $contact->lifecycle_stage);
        $this->assertSame(LeadStatus::InProgress, $contact->lead_status);
    }

    public function test_it_rejects_an_unknown_lifecycle_stage(): void
    {
        $import = ContactImporter::test()->import(['email' => 'grace@navy.mil', 'lifecycle_stage' => 'astronaut']);

        $this->assertTrue($import->errors()->has('lifecycle_stage'));
        $this->assertSame(0, Contact::query()->count());
    }

    public function test_the_list_page_offers_the_import_to_users_who_can_create_contacts(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->assertActionVisible('import');
    }

    public function test_the_import_is_hidden_from_users_who_cannot_create_contacts(): void
    {
        $this->denyAbilities([Contact::class], ['create']);

        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->assertActionHidden('import');
    }

    public function test_a_csv_runs_through_the_import_action_and_reports_what_failed(): void
    {
        Contact::factory()->create(['email' => 'already@here.com']);

        $csv = implode("\n", [
            'Email,First Name,Last Name',
            'grace@navy.mil,Grace,Hopper',
            'info@acme.com,,',
            'already@here.com,Dup,',
            'nope,,',
            'who@gone.invalid,,',
        ])."\n";

        Livewire::actingAs(User::factory()->create())
            ->test(ListContacts::class)
            ->callAction('import', data: [
                'file' => UploadedFile::fake()->createWithContent('contacts.csv', $csv),
                'columnMap' => ['email' => 'Email', 'first_name' => 'First Name', 'last_name' => 'Last Name'],
                'existing' => 'skip',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(['already@here.com', 'grace@navy.mil', 'info@acme.com'], Contact::query()->orderBy('email')->pluck('email')->all());

        $import = Import::query()->firstOrFail();

        $this->assertSame(5, $import->total_rows);
        $this->assertSame(2, $import->successful_rows);
        $this->assertSame(3, FailedImportRow::query()->count());
        $this->assertEqualsCanonicalizing(
            ['A contact with this email address already exists.', 'Not a valid email address', 'Domain does not accept email'],
            FailedImportRow::query()->get()->map(fn (FailedImportRow $row): string => (string) ($row->validation_error ?? ''))->all()
        );
    }
}
