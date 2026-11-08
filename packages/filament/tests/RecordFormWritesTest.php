<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Filament\Resources\DealResource\Pages\EditDeal;
use Odden\Filament\Resources\DealResource\RelationManagers\DealProductsRelationManager;
use Odden\Filament\Resources\PipelineResource\Pages\CreatePipeline;
use Odden\Filament\Resources\PipelineResource\Pages\EditPipeline;
use Odden\Filament\Resources\PipelineResource\RelationManagers\StagesRelationManager;
use Odden\Filament\Resources\QuoteResource\Pages\EditQuote;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\QuoteItem;

class RecordFormWritesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_quote_form_saves_its_line_items_through_the_action(): void
    {
        $quote = Quote::factory()->create();
        $kept = QuoteItem::create(['quote_id' => $quote->id, 'name' => 'Seat', 'unit_price' => 10, 'quantity' => 1, 'sort_order' => 1]);
        $removed = QuoteItem::create(['quote_id' => $quote->id, 'name' => 'Setup', 'unit_price' => 50, 'quantity' => 1, 'sort_order' => 2]);

        $component = Livewire::actingAs(User::factory()->create())->test(EditQuote::class, ['record' => $quote->id]);
        $state = $component->get('data.items');
        unset($state["record-{$removed->id}"]);
        $state["record-{$kept->id}"]['name'] = 'Seat renamed';
        $state['new-item'] = ['name' => 'Support', 'sku' => null, 'unit_price' => 30, 'quantity' => 2, 'discount_percent' => 0];

        $component->set('data.items', $state)->call('save')->assertHasNoFormErrors();

        $this->assertSame(['Seat renamed', 'Support'], $quote->items()->pluck('name')->all());
        $this->assertTrue($quote->items()->first()->is($kept));
        $this->assertFalse(QuoteItem::query()->whereKey($removed->id)->exists());
    }

    public function test_the_deal_products_tab_creates_edits_and_deletes_through_actions(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $deal = Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $pipeline->stages->first()->id, 'amount' => 0]);

        $component = Livewire::actingAs(User::factory()->create())
            ->test(DealProductsRelationManager::class, ['ownerRecord' => $deal, 'pageClass' => EditDeal::class])
            ->callAction(TestAction::make('create')->table(), ['name' => 'Licence', 'unit_price' => 100, 'quantity' => 2, 'discount_percent' => 0])
            ->assertHasNoFormErrors();

        $product = $deal->products()->firstOrFail();
        $this->assertSame(200.0, (float) $deal->fresh()->amount);

        $component->callAction(TestAction::make('edit')->table($product), ['name' => 'Licence', 'unit_price' => 100, 'quantity' => 3, 'discount_percent' => 0]);
        $this->assertSame(300.0, (float) $deal->fresh()->amount);

        $component->callAction(TestAction::make('delete')->table($product));
        $this->assertSame(0, $deal->products()->count());
    }

    public function test_a_default_pipeline_replaces_the_previous_default_when_saved_from_the_form(): void
    {
        $previous = Pipeline::factory()->create(['is_default' => true]);

        Livewire::actingAs(User::factory()->create())
            ->test(CreatePipeline::class)
            ->fillForm(['name' => 'Renewals', 'code' => 'renewals', 'is_default' => true, 'is_active' => true])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertFalse($previous->fresh()->is_default);
        $this->assertTrue(Pipeline::query()->where('code', 'renewals')->firstOrFail()->is_default);
    }

    public function test_the_stages_tab_adds_and_edits_a_stage_with_its_automations(): void
    {
        $pipeline = Pipeline::factory()->create();

        $component = Livewire::actingAs(User::factory()->create())
            ->test(StagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
            ->callAction(TestAction::make('create')->table(), [
                'name' => 'Intro',
                'code' => 'intro',
                'probability' => 10,
                'sort_order' => 1,
                'automations' => [['name' => 'Follow up', 'action_type' => 'create_task', 'is_active' => true]],
            ])
            ->assertHasNoFormErrors();

        $stage = $pipeline->stages()->firstOrFail();
        $this->assertSame(['Follow up'], $stage->automations()->pluck('name')->all());

        $component->callAction(TestAction::make('edit')->table($stage), ['name' => 'Intro call', 'code' => 'intro', 'probability' => 10, 'sort_order' => 1, 'automations' => []]);

        $this->assertSame('Intro call', $stage->fresh()->name);
        $this->assertSame(0, $stage->automations()->count());
    }
}
