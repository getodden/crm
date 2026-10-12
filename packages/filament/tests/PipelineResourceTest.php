<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Filament\Resources\PipelineResource\Pages\EditPipeline;
use Odden\Filament\Resources\PipelineResource\Pages\ListPipelines;
use Odden\Filament\Resources\PipelineResource\RelationManagers\StagesRelationManager;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;

class PipelineResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_pipelines_index(): void
    {
        $user = User::factory()->create();
        Pipeline::factory()->withStages()->count(2)->create();

        $response = $this->actingAs($user)->get('/admin/pipelines');

        $response->assertSuccessful();
    }

    public function test_authenticated_user_can_access_create_pipeline_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/pipelines/create');

        $response->assertSuccessful();
    }

    public function test_authenticated_user_can_access_edit_pipeline_page(): void
    {
        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create([
            'name' => 'Strategic Accounts Pipeline',
        ]);

        $response = $this->actingAs($user)->get("/admin/pipelines/{$pipeline->id}/edit");

        $response->assertSuccessful();
        $response->assertSee('Strategic Accounts Pipeline');
    }

    public function test_a_pipeline_with_deals_offers_no_delete_action(): void
    {
        $user = User::factory()->create();
        $used = Pipeline::factory()->withStages()->create();
        $unused = Pipeline::factory()->withStages()->create();
        Deal::factory()->create(['pipeline_id' => $used->id, 'stage_id' => $used->stages->first()->id]);

        Livewire::actingAs($user)
            ->test(EditPipeline::class, ['record' => $used->getRouteKey()])
            ->assertActionHidden('delete');

        Livewire::actingAs($user)
            ->test(EditPipeline::class, ['record' => $unused->getRouteKey()])
            ->assertActionVisible('delete');

        Livewire::actingAs($user)
            ->test(ListPipelines::class)
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->assertActionVisible(TestAction::make('delete')->table($unused));
    }

    public function test_a_stage_with_deals_offers_no_delete_action(): void
    {
        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create();
        [$used, $unused] = [$pipeline->stages[0], $pipeline->stages[1]];
        Deal::factory()->create(['pipeline_id' => $pipeline->id, 'stage_id' => $used->id]);

        Livewire::actingAs($user)
            ->test(StagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
            ->assertActionHidden(TestAction::make('delete')->table($used))
            ->assertActionVisible(TestAction::make('delete')->table($unused));
    }
}
