<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Filament\Pages\ExecutiveOverview;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\SalesQuota;

class MixedCurrencyTotalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_executive_overview_does_not_add_currencies_together(): void
    {
        $user = User::factory()->create();

        Deal::factory()->create(['amount' => 1000, 'currency' => 'USD', 'status' => 'open']);
        Deal::factory()->create(['amount' => 250, 'currency' => 'EUR', 'status' => 'open']);
        Deal::factory()->create(['amount' => 400, 'currency' => 'USD', 'status' => 'won', 'closed_at' => now()]);
        Deal::factory()->create(['amount' => 900, 'currency' => 'EUR', 'status' => 'won', 'closed_at' => now()]);

        SalesQuota::query()->create([
            'user_id' => $user->id,
            'target_amount' => 2000,
            'currency' => 'USD',
            'period_start' => now()->startOfYear(),
            'period_end' => now()->endOfYear(),
        ]);
        SalesQuota::query()->create([
            'user_id' => $user->id,
            'target_amount' => 9000,
            'currency' => 'EUR',
            'period_start' => now()->startOfYear(),
            'period_end' => now()->endOfYear(),
        ]);

        $page = Livewire::actingAs($user)->test(ExecutiveOverview::class);
        $instance = $page->instance();

        $this->assertSame('USD', $instance->reportingCurrency);
        $this->assertSame(1000.0, $instance->activePipeline);
        $this->assertSame(400.0, $instance->closedWonRevenue);
        $this->assertSame(2000.0, $instance->totalRevenueQuota);
        $this->assertArrayHasKey('EUR', $instance->otherCurrencyTotals);
    }
}
