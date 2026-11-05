<?php

declare(strict_types=1);

use Livewire\Livewire;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\ContactResource\Pages\ListContacts;
use Odden\Filament\Tests\Fixtures\User;

it('warns that there are too many records to sort when a list is sorted by an encrypted column above the fallback limit', function (): void {
    config(['odden-core.encryption.fallback_limit' => 2]);
    Contact::factory()->count(3)->create();

    Livewire::actingAs(User::factory()->create())
        ->test(ListContacts::class)
        ->sortTable('full_name')
        ->assertNotified('Too many records to sort by this column')
        ->assertCountTableRecords(3);
});
