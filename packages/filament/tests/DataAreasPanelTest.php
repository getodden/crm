<?php

declare(strict_types=1);

use Livewire\Livewire;
use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\AreaRevealed;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Tests\Fixtures\User;

function panelAreas(array $held, array $mayReveal = []): void
{
    app()->instance(AreaAccess::class, new class($held, $mayReveal) implements AreaAccess
    {
        public function __construct(private array $held, private array $mayReveal) {}

        public function allows(DataArea $area, int|string|null $userId = null): bool
        {
            return in_array($area, $this->held, true);
        }

        public function mayReveal(DataArea $area, int|string|null $userId = null): bool
        {
            return in_array($area, $this->mayReveal, true);
        }
    });
    app()->forgetInstance(Areas::class);
}

it('masks the Sensitive date of birth in the form and never saves it when the person lacks the area', function (): void {
    $contact = Contact::factory()->create(['phone' => null, 'date_of_birth' => '2011-03-02', 'job_title' => 'Analyst']);
    $this->actingAs(User::factory()->create());
    panelAreas([DataArea::Identity]);

    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->assertFormSet(['date_of_birth' => null, 'job_title' => 'Analyst'])
        ->fillForm(['job_title' => 'Director'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh()->date_of_birth->toDateString())->toBe('2011-03-02')
        ->and($contact->fresh()->job_title)->toBe('Director');
});

it('shows a mask in the contact list and finds nothing by search when Identity is not held', function (): void {
    Contact::factory()->create(['first_name' => 'Zebulon', 'last_name' => 'Quill', 'email' => 'zeb@quill.example']);
    $this->actingAs(User::factory()->create());
    panelAreas([DataArea::Sales]);

    Livewire::test(ListContacts::class)
        ->assertSee(Areas::MASK)
        ->assertDontSee('zeb@quill.example')
        ->searchTable('zeb')
        ->assertCountTableRecords(0);
});

it('shows everything when areas are not configured (a self-hosted install)', function (): void {
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02', 'email' => 'zeb@quill.example']);
    $this->actingAs(User::factory()->create());

    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->assertFormSet(['date_of_birth' => '2011-03-02', 'email' => 'zeb@quill.example']);
    Livewire::test(ListContacts::class)->assertSee('zeb@quill.example');
});

it('opens a hidden area for one record with a reason, and offers the action only to a role that may', function (): void {
    Event::fake([AreaRevealed::class]);
    $contact = Contact::factory()->create(['date_of_birth' => '2011-03-02']);
    $this->actingAs(User::factory()->create());

    panelAreas([DataArea::Identity], []);
    Livewire::test(EditContact::class, ['record' => $contact->getKey()])->assertActionHidden('revealAreas');

    panelAreas([DataArea::Identity], [DataArea::Sensitive]);
    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->assertActionVisible('revealAreas')
        ->callAction('revealAreas', ['reason' => 'short'])
        ->assertHasActionErrors();

    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->callAction('revealAreas', ['reason' => 'The parent phoned about this child.'])
        ->assertHasNoActionErrors();

    Event::assertDispatched(AreaRevealed::class);
    expect(app(Areas::class)->canSee(DataArea::Sensitive, $contact))->toBeTrue();
});
