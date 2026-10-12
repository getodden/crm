<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Filament\Pages\ExecutiveOverview;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\SalesQuota;

/**
 * Attainment is measured against the quotas that are set, all of them, and only them (#113).
 */
class ExecutiveOverviewQuotaTest extends TestCase
{
    use RefreshDatabase;

    private function wonDeal(User $owner, float $amount): Deal
    {
        $pipeline = Pipeline::factory()->withStages()->create();

        return Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'owner_id' => $owner->id,
            'amount' => $amount,
            'currency' => 'USD',
            'status' => 'won',
            'closed_at' => now(),
        ]);
    }

    private function quota(User $user, string $start, string $end, float $target): SalesQuota
    {
        return SalesQuota::query()->create([
            'user_id' => $user->id,
            'period_type' => 'monthly',
            'period_start' => $start,
            'period_end' => $end,
            'target_amount' => $target,
            'currency' => 'USD',
        ]);
    }

    public function test_without_quotas_no_target_is_invented(): void
    {
        $user = User::factory()->create();
        $this->wonDeal($user, 30000);

        $component = Livewire::actingAs($user)->test(ExecutiveOverview::class);

        $this->assertNull($component->get('totalRevenueQuota'));
        $this->assertNull($component->get('quotaAttainmentRate'));
        $this->assertNull($component->get('pipelineCoverageRatio'));

        $rep = $component->get('repLeaderboard')[0];
        $this->assertNull($rep['quota']);
        $this->assertNull($rep['attainment']);
        $component->assertSee('No quota set');
    }

    public function test_every_quota_of_a_rep_in_the_range_counts(): void
    {
        $user = User::factory()->create();
        $this->wonDeal($user, 30000);

        $quarter = now()->startOfQuarter();
        foreach ([0, 1, 2] as $month) {
            $start = $quarter->copy()->addMonths($month);
            $this->quota($user, $start->toDateString(), $start->copy()->endOfMonth()->toDateString(), 20000);
        }

        $component = Livewire::actingAs($user)->test(ExecutiveOverview::class)->assertSet('timeframe', 'quarter');

        $this->assertEquals(60000.0, $component->get('totalRevenueQuota'));
        $this->assertEquals(50.0, $component->get('quotaAttainmentRate'));

        $rep = $component->get('repLeaderboard')[0];
        $this->assertEquals(60000.0, $rep['quota']);
        $this->assertEquals(50.0, $rep['attainment']);
    }

    public function test_a_quota_spanning_the_whole_range_is_included(): void
    {
        $user = User::factory()->create();
        $this->wonDeal($user, 10000);
        $this->quota($user, now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString(), 120000);

        $component = Livewire::actingAs($user)->test(ExecutiveOverview::class)->call('setTimeframe', 'month');

        $this->assertEquals(120000.0, $component->get('totalRevenueQuota'));
    }
}
