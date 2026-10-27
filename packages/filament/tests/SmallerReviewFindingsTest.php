<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Contracts\TenantContext;
use Odden\Core\Models\Association;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\TenantGuard;
use Odden\Filament\OddenPlugin;
use Odden\Filament\Resources\CompanyResource\Pages\EditCompany;
use Odden\Filament\Resources\ContactResource;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\ContactResource\RelationManagers\CompaniesRelationManager;
use Odden\Filament\Resources\LeadRoutingRuleResource\Pages\CreateLeadRoutingRule;
use Odden\Filament\Resources\LeadRoutingRuleResource\Pages\ListLeadRoutingRules;
use Odden\Filament\Resources\PipelineResource\Pages\CreatePipeline;
use Odden\Filament\Resources\PipelineResource\Pages\EditPipeline;
use Odden\Filament\Resources\PipelineResource\RelationManagers\StagesRelationManager;
use Odden\Filament\Resources\SalesQuotaResource\Pages\CreateSalesQuota;
use Odden\Filament\Resources\SalesQuotaResource\Pages\ListSalesQuotas;
use Odden\Filament\Support\AttachesThroughAssociations;
use Odden\Filament\Support\Modules;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Enums\StageAutomationActionType;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\PipelineStage;
use Odden\Sales\Models\SalesQuota;
use Odden\Sales\Models\StageAutomation;

/**
 * The smaller review follow-ups (#119).
 */
class SmallerReviewFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_module_disabled_on_the_panel_adds_nothing_to_shared_screens(): void
    {
        Modules::register('marketing', contactRelationManagers: ['Fake\\CampaignsRelationManager'], authorizationResources: ['Fake\\CampaignResource']);
        Modules::register('service', contactRelationManagers: ['Fake\\TicketsRelationManager']);

        $panel = Filament::getPanel('admin');
        /** @var OddenPlugin $plugin */
        $plugin = $panel->getPlugin('odden');
        $plugin->disableModules('marketing');
        Filament::setCurrentPanel($panel);

        $this->assertSame(['Fake\\TicketsRelationManager'], Modules::contactRelationManagers());
        $this->assertSame([], Modules::authorizationResources());
        $this->assertNotContains('Fake\\CampaignsRelationManager', ContactResource::getRelations());
        $this->assertContains('Fake\\TicketsRelationManager', ContactResource::getRelations());
    }

    public function test_clearing_a_required_score_is_a_validation_error_not_a_database_error(): void
    {
        $user = User::factory()->create();
        $company = Company::factory()->create();
        // No phone: the factory's random number can fail the format rule, and this saves the whole form.
        $contact = Contact::factory()->create(['phone' => null]);

        Livewire::actingAs($user)
            ->test(EditCompany::class, ['record' => $company->id])
            ->set('data.health_score', null)
            ->set('data.health_status', null)
            ->call('save')
            ->assertHasFormErrors(['health_score', 'health_status']);

        // A contact's lead score is optional: cleared, it is saved as 0, never as a database error.
        Livewire::actingAs($user)
            ->test(EditContact::class, ['record' => $contact->id])
            ->set('data.lead_score', null)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(0, $contact->fresh()?->lead_score);
    }

    public function test_a_duplicate_pipeline_code_is_a_validation_error(): void
    {
        Pipeline::factory()->create(['code' => 'sales']);

        Livewire::actingAs(User::factory()->create())
            ->test(CreatePipeline::class)
            ->fillForm(['name' => 'Second', 'code' => 'sales'])
            ->call('create')
            ->assertHasFormErrors(['code']);

        $this->assertSame(1, Pipeline::query()->count());
    }

    public function test_there_is_one_default_pipeline(): void
    {
        $old = Pipeline::factory()->create(['is_default' => true]);
        $new = Pipeline::factory()->create(['is_default' => false]);

        Livewire::actingAs(User::factory()->create())
            ->test(EditPipeline::class, ['record' => $new->id])
            ->set('data.is_default', true)
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($new->refresh()->is_default);
        $this->assertFalse($old->refresh()->is_default);
    }

    public function test_stage_codes_are_unique_in_a_pipeline_and_a_stage_is_not_both_won_and_lost(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create();
        $other = Pipeline::factory()->create();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(StagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
            ->callAction(TestAction::make('create')->table(), ['name' => 'Again', 'code' => 'discovery', 'probability' => 10, 'sort_order' => 9])
            ->assertHasFormErrors(['code']);

        Livewire::actingAs($user)
            ->test(StagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
            ->callAction(TestAction::make('create')->table(), ['name' => 'Both', 'code' => 'both', 'probability' => 10, 'sort_order' => 9, 'is_closed_won' => true, 'is_closed_lost' => true])
            ->assertHasFormErrors(['is_closed_won']);

        // The same code in another pipeline is fine.
        Livewire::actingAs($user)
            ->test(StagesRelationManager::class, ['ownerRecord' => $other, 'pageClass' => EditPipeline::class])
            ->callAction(TestAction::make('create')->table(), ['name' => 'Discovery', 'code' => 'discovery', 'probability' => 10, 'sort_order' => 9, 'automations' => []])
            ->assertHasNoFormErrors();

        $this->assertSame(0, PipelineStage::query()->where('code', 'both')->count());
    }

    public function test_a_create_task_automation_takes_its_subject_and_due_days(): void
    {
        $pipeline = Pipeline::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(StagesRelationManager::class, ['ownerRecord' => $pipeline, 'pageClass' => EditPipeline::class])
            ->callAction(TestAction::make('create')->table(), [
                'name' => 'Proposal',
                'code' => 'proposal',
                'probability' => 50,
                'sort_order' => 3,
                'automations' => [[
                    'name' => 'Prep call',
                    'action_type' => StageAutomationActionType::CreateTask->value,
                    'action_payload' => ['task_subject' => 'Book the proposal walkthrough', 'due_days' => 5],
                    'is_active' => true,
                ]],
            ])
            ->assertHasNoFormErrors();

        $automation = StageAutomation::query()->firstOrFail();
        $this->assertSame('Book the proposal walkthrough', $automation->action_payload['task_subject'] ?? null);
        $this->assertEquals(5, $automation->action_payload['due_days'] ?? null);
    }

    public function test_a_quota_cannot_end_before_it_starts(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateSalesQuota::class)
            ->fillForm([
                'user_id' => $user->id,
                'period_type' => 'monthly',
                'target_amount' => 1000,
                'period_start' => '2026-10-31',
                'period_end' => '2026-10-01',
            ])
            ->call('create')
            ->assertHasFormErrors(['period_end']);

        $this->assertSame(0, SalesQuota::query()->count());
    }

    public function test_the_quota_list_shows_quotas(): void
    {
        $user = User::factory()->create();
        SalesQuota::query()->create([
            'user_id' => $user->id,
            'period_type' => 'monthly',
            'period_start' => now()->startOfMonth(),
            'period_end' => now()->endOfMonth(),
            'target_amount' => 1000,
            'currency' => 'USD',
        ]);

        Livewire::actingAs($user)->test(ListSalesQuotas::class)->assertSuccessful()->assertSee('Monthly');
    }

    public function test_attach_offers_and_links_only_records_the_user_can_see(): void
    {
        // A model scope stands in for the scoping a host applies in the resource's getEloquentQuery(); the picker's
        // own query does not carry it (the relationship builds it), so the constraint must come from the resource.
        Company::addGlobalScope('attach-scoping-test', fn (Builder $query) => $query->whereBlindExactNotIn('name', ['Hidden Co']));
        $visible = Company::factory()->create(['name' => 'Visible Co']);
        $hidden = Company::factory()->create(['name' => 'Hidden Co']);

        $offered = AttachesThroughAssociations::pickerQuery(Company::query()->withoutGlobalScope('attach-scoping-test'))
            ->pluck((new Company)->getQualifiedKeyName())
            ->all();

        $this->assertSame([$visible->id], array_values($offered));

        $contact = Contact::factory()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(CompaniesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
            ->callAction(TestAction::make('attach')->table(), ['recordId' => $hidden->id, 'type' => 'primary']);

        $this->assertSame(0, Association::query()->count());
    }

    public function test_a_cross_tenant_link_is_refused_with_a_message(): void
    {
        $contact = Contact::factory()->create();
        $company = Company::factory()->create();
        $this->app->instance(TenantContext::class, new class implements TenantContext
        {
            public function id(): int|string|null
            {
                return 5;
            }

            public function scopeUsers(Builder $users): Builder
            {
                return $users;
            }
        });
        $this->app->forgetInstance(TenantGuard::class);

        Livewire::actingAs(User::factory()->create())
            ->test(CompaniesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
            ->callAction(TestAction::make('attach')->table(), ['recordId' => $company->id, 'type' => 'primary'])
            ->assertNotified('Could not link these records');

        $this->assertSame(0, Association::query()->count());
    }

    public function test_the_reps_pool_is_summarised_once(): void
    {
        $users = User::factory()->count(3)->create();
        $rule = LeadRoutingRule::query()->create([
            'name' => 'EMEA',
            'strategy' => LeadRoutingStrategy::RoundRobin,
            'assigned_user_ids' => $users->pluck('id')->all(),
            'is_active' => true,
        ]);

        Livewire::actingAs($users->first())
            ->test(ListLeadRoutingRules::class)
            ->assertTableColumnStateSet('reps_pool', '3 reps', $rule)
            ->assertDontSee('3 reps, 3 reps');
    }

    public function test_routing_criteria_on_unsupported_fields_are_a_validation_error(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateLeadRoutingRule::class)
            ->fillForm([
                'name' => 'California',
                'strategy' => LeadRoutingStrategy::RoundRobin->value,
                'assigned_user_ids' => [$user->id],
                'criteria' => ['state' => 'CA'],
                'sort_order' => 0,
            ])
            ->call('create')
            ->assertHasFormErrors(['criteria']);

        $this->assertSame(0, LeadRoutingRule::query()->count());
    }
}
