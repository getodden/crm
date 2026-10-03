<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Sales\Actions\CalculatePipelineForecastAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

class ForecastTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_calculate_weighted_pipeline_forecast_and_metrics(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stages = $pipeline->stages;

        $discoveryStage = $stages->where('code', 'discovery')->firstOrFail(); // 20%
        $proposalStage = $stages->where('code', 'proposal_sent')->firstOrFail(); // 60%
        $wonStage = $stages->where('is_closed_won', true)->firstOrFail(); // 100%
        $lostStage = $stages->where('is_closed_lost', true)->firstOrFail(); // 0%

        // Open Deal 1: $100,000 at 20% -> weighted = $20,000
        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $discoveryStage->id,
            'amount' => 100000.00,
        ]);

        // Open Deal 2: $50,000 at 60% -> weighted = $30,000
        Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $proposalStage->id,
            'amount' => 50000.00,
        ]);

        // Closed Won: $80,000
        Deal::factory()->won()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $wonStage->id,
            'amount' => 80000.00,
        ]);

        // Closed Lost: $20,000
        Deal::factory()->lost()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $lostStage->id,
            'amount' => 20000.00,
        ]);

        $action = new CalculatePipelineForecastAction;
        $metrics = $action->execute($pipeline->id);

        $this->assertSame(150000.00, $metrics['open_value']);
        $this->assertSame(2, $metrics['open_count']);
        $this->assertSame(50000.00, $metrics['weighted_forecast']); // 20,000 + 30,000
        $this->assertSame(80000.00, $metrics['won_value']);
        $this->assertSame(1, $metrics['won_count']);
        $this->assertSame(1, $metrics['lost_count']);
        $this->assertSame(50.0, $metrics['win_rate']); // 1 won out of 2 closed = 50%
        $this->assertSame(62500.00, $metrics['average_deal_size']); // 250,000 / 4 = 62,500
    }

    public function test_forecast_can_be_scoped_to_a_team(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $stage = $pipeline->stages->where('code', 'discovery')->firstOrFail();

        Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'amount' => 1000, 'team_id' => 1]);
        Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $stage->id, 'amount' => 5000, 'team_id' => 2]);

        $all = $pipeline->forecast();
        $team1 = $pipeline->forecast(1);
        $team2 = app(CalculatePipelineForecastAction::class)->execute($pipeline->id, 2);

        $this->assertSame(6000.0, $all['open_value']);
        $this->assertSame(1000.0, $team1['open_value']);
        $this->assertSame(1, $team1['open_count']);
        $this->assertSame(5000.0, $team2['open_value']);
        $this->assertArrayHasKey('lost_reasons', $team1);
        $this->assertArrayHasKey('stale_deals_count', $team1);
    }
}
