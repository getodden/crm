<?php

declare(strict_types=1);

use Livewire\Livewire;
use Odden\Filament\Resources\DealResource\Pages\ListDeals;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

it('warns that there are too many records to sort when the deals are sorted by name above the fallback limit', function (): void {
    config(['odden-core.encryption.fallback_limit' => 2]);
    $pipeline = Pipeline::factory()->withStages()->create();
    Deal::factory()->count(3)->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id]);

    Livewire::actingAs(User::factory()->create())
        ->test(ListDeals::class)
        ->sortTable('name')
        ->assertNotified('Too many records to sort by this column')
        ->assertCountTableRecords(3);
});
