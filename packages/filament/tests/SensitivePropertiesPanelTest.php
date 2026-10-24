<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Odden\Core\Areas\AreaAccess;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Encryption\FieldEncrypter;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Jobs\ConvertSensitiveProperty;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\PropertyDefinition;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\CrmListResource\Pages\ListCrmLists;
use Odden\Filament\Resources\PropertyDefinitionResource\Pages\CreatePropertyDefinition;
use Odden\Filament\Resources\PropertyDefinitionResource\Pages\EditPropertyDefinition;
use Odden\Filament\Resources\PropertyDefinitionResource\Pages\ListPropertyDefinitions;
use Odden\Filament\Tests\Fixtures\User;

function panelHolds(array $held, array $mayReveal = []): void
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

function storedProps(Contact $contact): array
{
    return json_decode((string) DB::table('odden_contacts')->where('id', $contact->id)->value('properties'), true);
}

it('creates a sensitive property with the Sensitive area by default, and starts converting its values', function (): void {
    Queue::fake();
    $user = User::factory()->create();

    Livewire::actingAs($user)->test(CreatePropertyDefinition::class)
        ->fillForm(['entity_type' => 'contact', 'name' => 'tax_id', 'label' => 'Tax ID', 'type' => PropertyType::Text->value, 'is_sensitive' => true, 'is_searchable' => true])
        ->call('create')
        ->assertHasNoFormErrors();

    $definition = PropertyDefinition::query()->where('name', 'tax_id')->sole();

    expect($definition->is_sensitive)->toBeTrue()
        ->and($definition->data_area)->toBe('sensitive')
        // A sensitive property cannot be searched in a table, whatever was asked.
        ->and($definition->is_searchable)->toBeFalse();

    Queue::assertPushed(ConvertSensitiveProperty::class, fn (ConvertSensitiveProperty $job): bool => $job->definitionId === $definition->id);
});

it('takes the area that was chosen, and converts when the switch is flipped but not when only the area changes', function (): void {
    Queue::fake();
    $user = User::factory()->create();
    $definition = PropertyDefinition::query()->create(['entity_type' => 'contact', 'name' => 'region', 'label' => 'Region', 'type' => PropertyType::Text]);

    Livewire::actingAs($user)->test(EditPropertyDefinition::class, ['record' => $definition->getKey()])
        ->fillForm(['is_sensitive' => true, 'data_area' => 'sales'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($definition->fresh()->data_area)->toBe('sales');
    Queue::assertPushed(ConvertSensitiveProperty::class, 1);

    Livewire::actingAs($user)->test(EditPropertyDefinition::class, ['record' => $definition->getKey()])
        ->fillForm(['data_area' => 'service'])
        ->call('save');

    expect($definition->fresh()->data_area)->toBe('service');
    Queue::assertPushed(ConvertSensitiveProperty::class, 1);

    Livewire::actingAs($user)->test(EditPropertyDefinition::class, ['record' => $definition->getKey()])
        ->fillForm(['is_sensitive' => false])
        ->call('save');

    Queue::assertPushed(ConvertSensitiveProperty::class, 2);
});

it('shows a lock on sensitive properties in the list and offers no delete for them', function (): void {
    $user = User::factory()->create();
    $sensitive = PropertyDefinition::query()->create(['entity_type' => 'contact', 'name' => 'tax_id', 'label' => 'Tax ID', 'type' => PropertyType::Text, 'is_sensitive' => true]);
    $plain = PropertyDefinition::query()->create(['entity_type' => 'contact', 'name' => 'nick', 'label' => 'Nick', 'type' => PropertyType::Text]);

    Livewire::actingAs($user)->test(ListPropertyDefinitions::class)
        ->assertTableActionHidden('delete', $sensitive)
        ->assertTableActionVisible('delete', $plain);
});

it('shows a sensitive property on the form to a person who holds its area, encrypts it on save', function (): void {
    PropertyDefinition::query()->create(['entity_type' => 'contact', 'name' => 'tax_id', 'label' => 'Tax ID', 'type' => PropertyType::Text, 'is_sensitive' => true]);
    $contact = Contact::factory()->create(['phone' => null, 'properties' => ['tax_id' => 'A-1']]);
    $this->actingAs(User::factory()->create());
    panelHolds([DataArea::Identity, DataArea::Sensitive]);

    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->assertFormSet(['properties.tax_id' => 'A-1'])
        ->fillForm(['properties.tax_id' => 'B-2'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh()->properties['tax_id'])->toBe('B-2')
        ->and(app(FieldEncrypter::class)->isEncrypted(storedProps($contact)['tax_id']))->toBeTrue();
});

it('masks a sensitive property on the form, never saves it, and keeps the others editable', function (): void {
    PropertyDefinition::query()->create(['entity_type' => 'contact', 'name' => 'tax_id', 'label' => 'Tax ID', 'type' => PropertyType::Text, 'is_sensitive' => true]);
    PropertyDefinition::query()->create(['entity_type' => 'contact', 'name' => 'nick', 'label' => 'Nick', 'type' => PropertyType::Text]);
    $contact = Contact::factory()->create(['phone' => null, 'properties' => ['tax_id' => 'A-1', 'nick' => 'Zed']]);
    $this->actingAs(User::factory()->create());
    panelHolds([DataArea::Identity]);

    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->assertFormSet(['properties.tax_id' => null, 'properties.nick' => 'Zed'])
        ->assertSee('Hidden: outside your areas')
        ->fillForm(['properties.nick' => 'Ann', 'properties.tax_id' => 'hacked'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($contact->fresh()->properties)->toBe(['tax_id' => 'A-1', 'nick' => 'Ann']);
});

it('opens a sensitive property for one record with a reason', function (): void {
    PropertyDefinition::query()->create(['entity_type' => 'contact', 'name' => 'tax_id', 'label' => 'Tax ID', 'type' => PropertyType::Text, 'is_sensitive' => true]);
    $contact = Contact::factory()->create(['phone' => null, 'properties' => ['tax_id' => 'A-1']]);
    $this->actingAs(User::factory()->create());
    panelHolds([DataArea::Identity], mayReveal: [DataArea::Sensitive]);

    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->assertActionVisible('revealAreas')
        ->callAction('revealAreas', ['reason' => 'Checking the number with the customer']);

    Livewire::test(EditContact::class, ['record' => $contact->getKey()])
        ->assertFormSet(['properties.tax_id' => 'A-1']);
});

it('says why a list cannot be synced instead of failing, when too many records need checking', function (): void {
    PropertyDefinition::query()->create(['entity_type' => 'contact', 'name' => 'tax_id', 'label' => 'Tax ID', 'type' => PropertyType::Text, 'is_sensitive' => true]);
    Contact::factory()->count(3)->create(['properties' => ['tax_id' => 'A-1']]);
    config(['odden-core.encryption.fallback_limit' => 2]);
    $list = CrmList::query()->create(['name' => 'Tax', 'entity_type' => (new Contact)->getMorphClass(), 'type' => 'active', 'criteria' => [['property' => 'tax_id', 'operator' => 'equals', 'value' => 'A-1']]]);

    Livewire::actingAs(User::factory()->create())->test(ListCrmLists::class)
        ->callTableAction('sync', $list)
        ->assertNotified('This list cannot be synced');
});
