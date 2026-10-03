<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Filament\Resources\DealResource\Pages\KanbanDeals;
use Odden\Filament\Resources\DealResource\Pages\ViewDeal;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\LostReason;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

class DealResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_deals_index(): void
    {
        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
        Deal::factory()->count(3)->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id]);

        $response = $this->actingAs($user)->get('/admin/deals');

        $response->assertSuccessful();
    }

    public function test_authenticated_user_can_access_deals_kanban_board(): void
    {
        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
        $stage = $pipeline->stages->first();

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $stage->id,
            'name' => 'SpaceX Heavy Booster Expansion',
            'amount' => 500000.00,
        ]);

        $response = $this->actingAs($user)->get('/admin/deals/board');

        $response->assertSuccessful();
        $response->assertSee('Deals Pipeline Board');
        $response->assertSee('SpaceX Heavy Booster Expansion');
        $response->assertSee('$500,000.00');
    }

    public function test_kanban_board_can_move_deal_between_stages(): void
    {
        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
        $stages = $pipeline->stages;
        $discovery = $stages[0];
        $negotiation = $stages[3];

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $discovery->id,
            'status' => DealStatus::Open,
        ]);

        Livewire::actingAs($user)
            ->test(KanbanDeals::class)
            ->call('moveDeal', $deal->id, $negotiation->id)
            ->assertSuccessful();

        $deal->refresh();
        $this->assertSame($negotiation->id, $deal->stage_id);
    }

    public function test_authenticated_user_can_access_view_deal_page(): void
    {
        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'name' => 'Stripe Global Issuing Contract',
        ]);

        $response = $this->actingAs($user)->get("/admin/deals/{$deal->id}");

        $response->assertSuccessful();
        $response->assertSee('Stripe Global Issuing Contract');
    }

    public function test_kanban_board_rejects_move_to_stage_of_another_pipeline(): void
    {
        $this->markTestIncomplete('moveDeal does not check the stage belongs to the deal pipeline; fixed by #50.');


        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
        $other = Pipeline::factory()->withStages()->create(['is_default' => false]);
        $discovery = $pipeline->stages[0];
        $foreignStage = $other->stages[3];

        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $discovery->id,
            'status' => DealStatus::Open,
        ]);

        try {
            Livewire::actingAs($user)
                ->test(KanbanDeals::class)
                ->call('moveDeal', $deal->id, $foreignStage->id);
        } catch (\Throwable) {
            // Rejection may surface as an exception; the deal must be unchanged either way.
        }

        $deal->refresh();
        $this->assertSame($discovery->id, $deal->stage_id);
        $this->assertSame($pipeline->id, $deal->pipeline_id);
        $this->assertSame(DealStatus::Open, $deal->status);
    }

    public function test_view_deal_mark_lost_stores_lost_reason_enum_value(): void
    {
        $this->markTestIncomplete('Mark Lost takes free text instead of a LostReason enum value; fixed by #50.');


        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'status' => DealStatus::Open,
        ]);

        Livewire::actingAs($user)
            ->test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('mark_lost', ['lost_reason' => 'Budget cuts, chose competitor'])
            ->assertHasActionErrors(['lost_reason']);

        $this->assertSame(DealStatus::Open, $deal->refresh()->status);

        Livewire::actingAs($user)
            ->test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('mark_lost', ['lost_reason' => LostReason::Price->value])
            ->assertHasNoActionErrors();

        $deal->refresh();
        $this->assertSame(DealStatus::Lost, $deal->status);
        $this->assertSame(LostReason::Price->value, $deal->lost_reason);
    }
}
