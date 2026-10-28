<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Filament\Resources\DealResource\Pages\ListDeals;
use Odden\Filament\Resources\QuoteResource\Pages\ListQuotes;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\QuoteStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;

class RestoreDeletedRecordsTest extends TestCase
{
    use RefreshDatabase;

    private function deal(): Deal
    {
        $pipeline = Pipeline::factory()->withStages()->create();

        return Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id]);
    }

    public function test_a_deleted_deal_can_be_restored_from_its_row(): void
    {
        $deal = $this->deal();
        $deal->delete();

        Livewire::actingAs(User::factory()->create())
            ->test(ListDeals::class)
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$deal])
            ->callTableAction('restore', $deal);

        $this->assertFalse($deal->fresh()?->trashed());
    }

    public function test_a_deleted_quote_can_be_found_and_restored(): void
    {
        $quote = Quote::factory()->create(['deal_id' => $this->deal()->id, 'status' => QuoteStatus::Draft]);
        $quote->delete();

        Livewire::actingAs(User::factory()->create())
            ->test(ListQuotes::class)
            ->assertCanNotSeeTableRecords([$quote])
            ->filterTable('trashed', false)
            ->assertCanSeeTableRecords([$quote])
            ->callTableAction('restore', $quote);

        $this->assertFalse($quote->fresh()?->trashed());
    }
}
