<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Enums\CustomerHealthStatus;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Pages\ExecutiveOverview;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\SalesQuota;

class ExecutiveOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_executive_overview_page(): void
    {
        $user = User::factory()->create();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Proposal Delivered',
            'probability' => 75,
        ]);

        Deal::factory()->create([
            'name' => 'Acme Megadeal',
            'amount' => 50000,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'status' => 'open',
        ]);

        Company::factory()->create([
            'name' => 'Risk Enterprise Corp',
            'health_score' => 25,
            'health_status' => CustomerHealthStatus::AtRisk,
            'account_tier' => 'tier_1',
        ]);

        Contact::factory()->create([
            'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead,
        ]);

        $response = $this->actingAs($user)->get('/admin/executive-overview');

        $response->assertSuccessful();
        $response->assertSee('RevOps Executive Dashboard');
        $response->assertSee('Active Pipeline');
        $response->assertSee('Acme Megadeal');
        $response->assertSee('Full Flywheel Conversion Funnel');
        $response->assertSee('Risk Enterprise Corp');
    }

    public function test_executive_overview_computes_metrics_and_handles_timeframe_updates(): void
    {
        $user = User::factory()->create();

        $pipeline = Pipeline::factory()->create();
        $stage = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Negotiation',
            'probability' => 80,
        ]);

        Deal::factory()->create([
            'name' => 'Enterprise Cloud Migration',
            'amount' => 100000,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'status' => 'won',
            'closed_at' => now(),
        ]);

        SalesQuota::create([
            'user_id' => $user->id,
            'pipeline_id' => $pipeline->id,
            'period_type' => 'quarterly',
            'period_start' => now()->startOfQuarter(),
            'period_end' => now()->endOfQuarter(),
            'target_amount' => 200000,
            'currency' => 'USD',
        ]);

        $component = Livewire::actingAs($user)
            ->test(ExecutiveOverview::class)
            ->assertSet('timeframe', 'quarter');

        $this->assertEquals(100000.0, $component->get('closedWonRevenue'));
        $this->assertEquals(200000.0, $component->get('totalRevenueQuota'));
        $this->assertEquals(50.0, $component->get('quotaAttainmentRate'));

        // Switch timeframe
        $component->call('setTimeframe', 'year')
            ->assertSet('timeframe', 'year')
            ->assertHasNoErrors();
    }

    public function test_executive_overview_displays_rep_leaderboard_and_deal_rot_radar(): void
    {
        $ae = User::factory()->create(['name' => 'Alice TopCloser', 'email' => 'alice@company.com']);

        $pipeline = Pipeline::factory()->create();
        $staleStage = PipelineStage::factory()->create([
            'pipeline_id' => $pipeline->id,
            'name' => 'Technical Validation',
            'rot_after_days' => 5,
        ]);

        // Stalled deal for Alice
        $stalledDeal = Deal::factory()->create([
            'name' => 'Stalled FinTech Contract',
            'amount' => 85000,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $staleStage->id,
            'owner_id' => $ae->id,
            'status' => 'open',
            'created_at' => now()->subDays(15),
        ]);

        // Won deal for Alice
        Deal::factory()->create([
            'name' => 'Closed SaaS Contract',
            'amount' => 75000,
            'pipeline_id' => $pipeline->id,
            'stage_id' => $staleStage->id,
            'owner_id' => $ae->id,
            'status' => 'won',
            'closed_at' => now(),
        ]);

        SalesQuota::create([
            'user_id' => $ae->id,
            'pipeline_id' => $pipeline->id,
            'period_type' => 'quarterly',
            'period_start' => now()->startOfQuarter(),
            'period_end' => now()->endOfQuarter(),
            'target_amount' => 100000,
            'currency' => 'USD',
        ]);

        $response = $this->actingAs($ae)->get('/admin/executive-overview');

        $response->assertSuccessful();
        $response->assertSee('AE Quota Attainment Leaderboard');
        $response->assertSee('Alice TopCloser');
        $response->assertSee('(75%)');
        $response->assertSee('Stalled and Rotting Deals Radar');
        $response->assertSee('Stalled FinTech Contract');
        $response->assertSee('Print Brief');

        $component = Livewire::actingAs($ae)->test(ExecutiveOverview::class);
        $leaderboard = $component->get('repLeaderboard');
        $this->assertNotEmpty($leaderboard);
        $this->assertEquals('Alice TopCloser', $leaderboard[0]['name']);
        $this->assertEquals(75.0, $leaderboard[0]['attainment']);

        $rotting = $component->get('stalledRottingDeals');
        $this->assertNotEmpty($rotting);
        $this->assertTrue($rotting->contains('name', 'Stalled FinTech Contract'));
    }
}
