<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Odden\Filament\Resources\DealResource\Pages\CreateDeal;
use Odden\Filament\Resources\DealResource\Pages\EditDeal;
use Odden\Filament\Resources\DealResource\Pages\KanbanDeals;
use Odden\Filament\Resources\DealResource\Pages\ViewDeal;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Enums\LostReason;
use Odden\Sales\Enums\StageAutomationActionType;
use Odden\Sales\Events\DealMovedStage;
use Odden\Sales\Events\DealWon;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\StageAutomation;

/**
 * Stage and status change only through the stage actions (#104), and a refused move is reported, not a 500 (#109).
 */
class DealStageChangesTest extends TestCase
{
    use RefreshDatabase;

    private function pipeline(): Pipeline
    {
        return Pipeline::factory()->withStages()->create(['is_default' => true]);
    }

    private function stage(Pipeline $pipeline, string $code): PipelineStage
    {
        return $pipeline->stages()->where('code', $code)->firstOrFail();
    }

    private function requireAcceptedQuote(PipelineStage $stage): void
    {
        StageAutomation::query()->create([
            'stage_id' => $stage->id,
            'name' => 'Signed quote first',
            'action_type' => StageAutomationActionType::RequireAcceptedQuote,
            'is_active' => true,
        ]);
    }

    public function test_the_edit_form_does_not_change_stage_or_status(): void
    {
        $pipeline = $this->pipeline();
        $discovery = $this->stage($pipeline, 'discovery');
        $won = $this->stage($pipeline, 'closed_won');
        $this->requireAcceptedQuote($won);
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $discovery->id, 'name' => 'Old name']);
        Event::fake([DealWon::class]);

        Livewire::actingAs(User::factory()->create())
            ->test(EditDeal::class, ['record' => $deal->id])
            ->set('data.stage_id', $won->id)
            ->set('data.status', DealStatus::Won->value)
            ->set('data.name', 'New name')
            ->call('save')
            ->assertHasNoFormErrors();

        $deal->refresh();
        $this->assertSame('New name', $deal->name);
        $this->assertSame($discovery->id, $deal->stage_id);
        $this->assertSame(DealStatus::Open, $deal->status);
        Event::assertNotDispatched(DealWon::class);
    }

    public function test_a_new_deal_starts_open_and_cannot_be_created_in_a_closed_stage(): void
    {
        $pipeline = $this->pipeline();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateDeal::class)
            ->fillForm([
                'name' => 'Closed on arrival',
                'pipeline_id' => $pipeline->id,
                'stage_id' => $this->stage($pipeline, 'closed_won')->id,
                'amount' => 100,
                'currency' => 'USD',
            ])
            ->call('create')
            ->assertHasFormErrors(['stage_id']);

        $this->assertSame(0, Deal::query()->count());

        Livewire::actingAs($user)
            ->test(CreateDeal::class)
            ->fillForm([
                'name' => 'Fresh deal',
                'pipeline_id' => $pipeline->id,
                'stage_id' => $this->stage($pipeline, 'discovery')->id,
                'amount' => 100,
                'currency' => 'USD',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $deal = Deal::query()->firstOrFail();
        $this->assertSame(DealStatus::Open, $deal->status);
        $this->assertSame(1, DealStageHistory::query()->where('deal_id', $deal->id)->count());
    }

    public function test_change_stage_moves_the_deal_through_the_stage_action(): void
    {
        $pipeline = $this->pipeline();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $this->stage($pipeline, 'discovery')->id]);
        $negotiation = $this->stage($pipeline, 'negotiation');
        Event::fake([DealMovedStage::class]);

        Livewire::actingAs(User::factory()->create())
            ->test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('change_stage', ['stage_id' => $negotiation->id])
            ->assertHasNoActionErrors();

        $this->assertSame($negotiation->id, $deal->refresh()->stage_id);
        Event::assertDispatched(DealMovedStage::class);
        $this->assertTrue(DealStageHistory::query()->where('deal_id', $deal->id)->where('to_stage_id', $negotiation->id)->exists());
    }

    public function test_change_stage_to_a_lost_stage_requires_a_reason(): void
    {
        $pipeline = $this->pipeline();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $this->stage($pipeline, 'discovery')->id]);
        $lost = $this->stage($pipeline, 'closed_lost');

        Livewire::actingAs(User::factory()->create())
            ->test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('change_stage', ['stage_id' => $lost->id])
            ->assertHasActionErrors(['lost_reason']);

        $this->assertSame(DealStatus::Open, $deal->refresh()->status);

        Livewire::actingAs(User::factory()->create())
            ->test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('change_stage', ['stage_id' => $lost->id, 'lost_reason' => LostReason::Price->value])
            ->assertHasNoActionErrors();

        $deal->refresh();
        $this->assertSame(DealStatus::Lost, $deal->status);
        $this->assertSame(LostReason::Price->value, $deal->lost_reason);
    }

    public function test_mark_won_reports_an_unmet_stage_requirement_instead_of_failing(): void
    {
        $pipeline = $this->pipeline();
        $this->requireAcceptedQuote($this->stage($pipeline, 'closed_won'));
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $this->stage($pipeline, 'discovery')->id]);

        Livewire::actingAs(User::factory()->create())
            ->test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('mark_won')
            ->assertSuccessful()
            ->assertNotified('Deal Not Moved');

        $this->assertSame(DealStatus::Open, $deal->refresh()->status);
    }

    public function test_mark_lost_reports_a_pipeline_without_a_lost_stage(): void
    {
        $pipeline = Pipeline::factory()->create();
        $open = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'is_closed_won' => false, 'is_closed_lost' => false]);
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $open->id]);

        Livewire::actingAs(User::factory()->create())
            ->test(ViewDeal::class, ['record' => $deal->id])
            ->callAction('mark_lost', ['lost_reason' => LostReason::Price->value])
            ->assertSuccessful()
            ->assertNotified('Deal Not Moved');

        $this->assertSame(DealStatus::Open, $deal->refresh()->status);
    }

    public function test_the_board_reports_an_unmet_stage_requirement(): void
    {
        $pipeline = $this->pipeline();
        $won = $this->stage($pipeline, 'closed_won');
        $this->requireAcceptedQuote($won);
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $this->stage($pipeline, 'discovery')->id]);

        Livewire::actingAs(User::factory()->create())
            ->test(KanbanDeals::class)
            ->call('moveDeal', $deal->id, $won->id)
            ->assertSuccessful()
            ->assertNotified('Deal Not Moved');

        $this->assertSame(DealStatus::Open, $deal->refresh()->status);
    }

    public function test_the_board_does_not_close_a_deal_as_lost_without_a_reason(): void
    {
        $pipeline = $this->pipeline();
        $discovery = $this->stage($pipeline, 'discovery');
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $discovery->id]);

        Livewire::actingAs(User::factory()->create())
            ->test(KanbanDeals::class)
            ->call('moveDeal', $deal->id, $this->stage($pipeline, 'closed_lost')->id)
            ->assertSuccessful()
            ->assertNotified('Deal Not Moved');

        $deal->refresh();
        $this->assertSame($discovery->id, $deal->stage_id);
        $this->assertSame(DealStatus::Open, $deal->status);
    }
}
