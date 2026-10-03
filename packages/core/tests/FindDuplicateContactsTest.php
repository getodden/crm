<?php

declare(strict_types=1);

use Odden\Core\Actions\FindDuplicateContactsAction;
use Odden\Core\Models\Contact;

/**
 * @return array<string, list<int>> match_field:match_value => contact ids
 */
function duplicateGroups(): array
{
    $groups = [];
    foreach (app(FindDuplicateContactsAction::class)->execute() as $group) {
        $groups["{$group['match_field']}:{$group['match_value']}"] = $group['contacts']->pluck('id')->all();
    }

    return $groups;
}

it('matches emails case-insensitively', function (): void {
    $a = Contact::factory()->create(['first_name' => 'Ann', 'last_name' => 'One', 'email' => 'Shared@Example.com', 'phone' => null]);
    $b = Contact::factory()->create(['first_name' => 'Bob', 'last_name' => 'Two', 'email' => 'shared@example.com', 'phone' => null]);
    Contact::factory()->create(['first_name' => 'Cy', 'last_name' => 'Three', 'email' => 'other@example.com', 'phone' => null]);

    expect(duplicateGroups())->toBe(['email:shared@example.com' => [$a->id, $b->id]]);
});

it('matches phone numbers on their digits only', function (): void {
    $a = Contact::factory()->create(['first_name' => 'Ann', 'last_name' => 'One', 'email' => 'a@example.com', 'phone' => '(555) 010-1234']);
    $b = Contact::factory()->create(['first_name' => 'Bob', 'last_name' => 'Two', 'email' => 'b@example.com', 'phone' => '555.010.1234']);
    Contact::factory()->create(['first_name' => 'Cy', 'last_name' => 'Three', 'email' => 'c@example.com', 'phone' => '555-010-9999']);

    expect(duplicateGroups())->toBe(['phone:5550101234' => [$a->id, $b->id]]);
});

it('matches first and last name case-insensitively', function (): void {
    $a = Contact::factory()->create(['first_name' => 'Dana', 'last_name' => 'Scully', 'email' => 'dana1@example.com', 'phone' => null]);
    $b = Contact::factory()->create(['first_name' => 'dana', 'last_name' => ' SCULLY ', 'email' => 'dana2@example.com', 'phone' => null]);
    Contact::factory()->create(['first_name' => 'Dana', 'last_name' => 'Mulder', 'email' => 'dana3@example.com', 'phone' => null]);

    expect(duplicateGroups())->toBe(['name:dana scully' => [$a->id, $b->id]]);
});

it('does not report contacts without a last name as name duplicates', function (): void {
    Contact::factory()->create(['first_name' => 'Cher', 'last_name' => null, 'email' => 'cher1@example.com', 'phone' => null]);
    Contact::factory()->create(['first_name' => 'Cher', 'last_name' => null, 'email' => 'cher2@example.com', 'phone' => null]);

    expect(duplicateGroups())->toBe([]);
});

it('reports a group of the same contacts once, under the first matching field', function (): void {
    $a = Contact::factory()->create(['first_name' => 'Eve', 'last_name' => 'Adams', 'email' => 'eve@example.com', 'phone' => '555 000 1111']);
    $b = Contact::factory()->create(['first_name' => 'Eve', 'last_name' => 'Adams', 'email' => 'EVE@example.com', 'phone' => '5550001111']);

    expect(duplicateGroups())->toBe(['email:eve@example.com' => [$a->id, $b->id]]);
});
