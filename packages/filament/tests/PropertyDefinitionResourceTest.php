<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Enums\PropertyType;
use Odden\Core\Models\Contact;
use Odden\Core\Models\PropertyDefinition;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Resources\PropertyDefinitionResource;
use Odden\Filament\Resources\PropertyDefinitionResource\Pages\CreatePropertyDefinition;
use Odden\Filament\Tests\Fixtures\User;

class PropertyDefinitionResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deal_is_offered_as_an_entity_type_when_sales_is_installed(): void
    {
        $this->assertSame(
            ['contact' => 'Contact', 'company' => 'Company', 'deal' => 'Deal'],
            PropertyDefinitionResource::entityTypeOptions()
        );
    }

    public function test_a_duplicate_property_key_is_a_form_error_but_the_same_key_on_another_entity_is_fine(): void
    {
        $user = User::factory()->create();
        $fields = ['entity_type' => 'contact', 'name' => 'annual_budget', 'label' => 'Annual budget', 'type' => PropertyType::Text->value];

        Livewire::actingAs($user)->test(CreatePropertyDefinition::class)->fillForm($fields)->call('create')->assertHasNoFormErrors();

        Livewire::actingAs($user)->test(CreatePropertyDefinition::class)->fillForm($fields)->call('create')->assertHasFormErrors(['name']);

        Livewire::actingAs($user)->test(CreatePropertyDefinition::class)->fillForm(['entity_type' => 'company'] + $fields)->call('create')->assertHasNoFormErrors();

        $this->assertSame(2, PropertyDefinition::query()->where('name', 'annual_budget')->count());
    }

    public function test_a_deal_property_definition_can_be_created(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreatePropertyDefinition::class)
            ->fillForm([
                'entity_type' => 'deal',
                'name' => 'procurement_stage',
                'label' => 'Procurement Stage',
                'type' => PropertyType::Text->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('odden_properties', ['entity_type' => 'deal', 'name' => 'procurement_stage']);
    }

    public function test_searchable_properties_get_a_searchable_column_in_the_list(): void
    {
        $user = User::factory()->create();

        PropertyDefinition::create(['entity_type' => 'contact', 'name' => 'favorite_color', 'label' => 'Favorite Color', 'type' => PropertyType::Text, 'is_searchable' => true]);
        PropertyDefinition::create(['entity_type' => 'contact', 'name' => 'shoe_size', 'label' => 'Shoe Size', 'type' => PropertyType::Text, 'is_searchable' => false]);

        $teal = Contact::factory()->create(['properties' => ['favorite_color' => 'teal', 'shoe_size' => '42']]);
        $crimson = Contact::factory()->create(['properties' => ['favorite_color' => 'crimson', 'shoe_size' => '42']]);

        Livewire::actingAs($user)
            ->test(ListContacts::class)
            ->assertTableColumnExists('properties.favorite_color')
            ->assertTableColumnDoesNotExist('properties.shoe_size')
            ->searchTable('teal')
            ->assertCanSeeTableRecords([$teal])
            ->assertCanNotSeeTableRecords([$crimson]);
    }
}
