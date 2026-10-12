<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Events\ActivityLogged;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\ListMembership;
use Odden\Filament\Pages\ExecutiveOverview;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\CrmListResource\Pages\EditCrmList;
use Odden\Filament\Resources\CrmListResource\RelationManagers\MembersRelationManager;
use Odden\Filament\Resources\DealResource\Pages\KanbanDeals;
use Odden\Filament\Resources\DealResource\Pages\ListDeals;
use Odden\Filament\Resources\RelationManagers\ActivitiesRelationManager;
use Odden\Filament\Resources\SalesQuotaResource\Pages\ListSalesQuotas;
use Odden\Filament\Resources\SalesSequenceResource\Pages\ListSalesSequences;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Actions\CalculateDealHealthScoreAction;
use Odden\Sales\Actions\CalculateQuotaAttainmentAction;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\DealStageHistory;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\Quote;
use Odden\Sales\Models\SalesQuota;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Log Activity goes through LogActivityAction (#116); tables and pages do their heavy work once per row and load
 * what they read up front (#117).
 */
class TableQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Model::preventLazyLoading(false);

        parent::tearDown();
    }

    /**
     * @return list<Deal>
     */
    private function openDeals(int $count): array
    {
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);

        $deals = Deal::factory()->count($count)->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages->first()->id,
            'status' => 'open',
        ]);

        // Every deal has entered its stage (the create page and stage changes record it).
        foreach ($deals as $deal) {
            DealStageHistory::query()->create(['deal_id' => $deal->id, 'to_stage_id' => $deal->stage_id, 'entered_at' => now()->subDays(3)]);
        }

        return $deals->all();
    }

    public function test_log_activity_goes_through_the_action(): void
    {
        $contact = Contact::factory()->create();
        Event::fake([ActivityLogged::class]);

        Livewire::actingAs(User::factory()->create())
            ->test(ActivitiesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
            ->callAction(TestAction::make('create')->table(), [
                'type' => ActivityType::Call->value,
                'title' => 'Discovery call',
                'status' => ActivityStatus::Completed->value,
            ])
            ->assertHasNoFormErrors();

        $activity = Activity::query()->where('title', 'Discovery call')->firstOrFail();
        $this->assertSame($contact->id, $activity->subject_id);
        $this->assertNotNull($activity->completed_at);
        Event::assertDispatched(ActivityLogged::class);
    }

    public function test_the_deals_table_scores_the_page_in_one_batch_without_lazy_loading(): void
    {
        $this->openDeals(3);
        $batches = [];
        $this->app->bind(CalculateDealHealthScoreAction::class, function () use (&$batches): CalculateDealHealthScoreAction {
            return new class($batches) extends CalculateDealHealthScoreAction
            {
                /** @param  list<int>  $batches */
                public function __construct(private array &$batches) {}

                public function executeMany(iterable $deals): array
                {
                    $scores = parent::executeMany($deals);
                    $this->batches[] = count($scores);

                    return $scores;
                }
            };
        });

        Model::preventLazyLoading();

        Livewire::actingAs(User::factory()->create())->test(ListDeals::class)->assertSuccessful();

        $this->assertSame([3], $batches);
    }

    /**
     * Queries a page runs, measured after a warm-up render so one-off lookups (settings, permissions) don't count.
     *
     * @param  class-string  $page
     */
    private function queriesToRender(string $page, User $user): int
    {
        Livewire::actingAs($user)->test($page)->assertSuccessful();

        DB::flushQueryLog();
        DB::enableQueryLog();
        Livewire::actingAs($user)->test($page)->assertSuccessful();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function dealPages(): array
    {
        return [
            'deals table' => [ListDeals::class],
            'deal kanban' => [KanbanDeals::class],
            'sales cockpit' => [SalesCockpit::class],
            'executive overview' => [ExecutiveOverview::class],
        ];
    }

    #[DataProvider('dealPages')]
    public function test_deal_pages_run_the_same_queries_for_more_deals(string $page): void
    {
        $user = User::factory()->create();
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
        $stage = $pipeline->stages->first();
        $stage->update(['rot_after_days' => 2]);

        $addDeals = function (int $count) use ($pipeline, $stage, $user): void {
            foreach (Deal::factory()->count($count)->create([
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'status' => 'open',
                'owner_id' => $user->id,
                'expected_close_date' => now()->addDays(10),
            ]) as $deal) {
                DealStageHistory::query()->create(['deal_id' => $deal->id, 'to_stage_id' => $deal->stage_id, 'entered_at' => now()->subDays(30)]);
                $contact = Contact::factory()->create(['lifecycle_stage' => 'lead']);
                $deal->contacts()->attach($contact->id, ['parent_type' => $deal->getMorphClass(), 'child_type' => $contact->getMorphClass()]);
                $deal->logActivity(ActivityType::Call, 'Discovery call');
                Quote::factory()->create(['deal_id' => $deal->id]);
            }
        };

        $addDeals(2);
        $few = $this->queriesToRender($page, $user);

        $addDeals(6);
        $many = $this->queriesToRender($page, $user);

        $this->assertSame($few, $many, "{$page} runs more queries for more deals");
    }

    public function test_the_quota_table_computes_attainment_once_per_quota(): void
    {
        $user = User::factory()->create();
        foreach ([1, 2] as $i) {
            SalesQuota::query()->create([
                'user_id' => $user->id,
                'period_type' => 'monthly',
                'period_start' => now()->startOfMonth(),
                'period_end' => now()->endOfMonth(),
                'target_amount' => 1000 * $i,
                'currency' => 'USD',
            ]);
        }
        $calls = 0;
        $this->app->bind(CalculateQuotaAttainmentAction::class, function () use (&$calls): CalculateQuotaAttainmentAction {
            return new class($calls) extends CalculateQuotaAttainmentAction
            {
                public function __construct(private int &$calls) {}

                public function execute(SalesQuota $quota, ?int $teamId = null): array
                {
                    $this->calls++;

                    return parent::execute($quota, $teamId);
                }
            };
        });

        Livewire::actingAs($user)->test(ListSalesQuotas::class)->assertSuccessful();

        $this->assertSame(2, $calls);
    }

    public function test_the_sequences_table_counts_active_enrollments_in_the_list_query(): void
    {
        $sequence = SalesSequence::query()->create([
            'name' => 'Outbound',
            'is_active' => true,
            'steps' => [['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Call']],
        ]);
        foreach (['active', 'active', 'completed'] as $status) {
            SalesSequenceEnrollment::query()->create([
                'sequence_id' => $sequence->id,
                'contact_id' => Contact::factory()->create()->id,
                'current_step' => 1,
                'status' => $status,
            ]);
        }

        Livewire::actingAs(User::factory()->create())
            ->test(ListSalesSequences::class)
            ->assertTableColumnStateSet('enrollments_count', 2, $sequence);
    }

    public function test_list_members_are_loaded_up_front(): void
    {
        $list = CrmList::factory()->create();
        foreach (Contact::factory()->count(4)->create() as $contact) {
            ListMembership::query()->create([
                'list_id' => $list->id,
                'member_type' => $contact->getMorphClass(),
                'member_id' => $contact->id,
                'added_at' => now(),
            ]);
        }

        Model::preventLazyLoading();

        Livewire::actingAs(User::factory()->create())
            ->test(MembersRelationManager::class, ['ownerRecord' => $list, 'pageClass' => EditCrmList::class])
            ->assertSuccessful();

        $this->assertTrue(true, 'Rendering the members did not lazy-load each member');
    }

    public function test_the_cockpit_and_executive_overview_load_stage_history_up_front(): void
    {
        $this->openDeals(3);
        $user = User::factory()->create();
        $historyQueries = 0;
        DB::listen(function ($query) use (&$historyQueries): void {
            if (str_contains($query->sql, 'odden_deal_stage_history')) {
                $historyQueries++;
            }
        });

        Livewire::actingAs($user)->test(SalesCockpit::class)->get('guidedActions');
        $cockpitQueries = $historyQueries;

        $historyQueries = 0;
        Livewire::actingAs($user)->test(ExecutiveOverview::class)->get('stalledRottingDeals');

        $this->assertLessThanOrEqual(1, $cockpitQueries);
        $this->assertLessThanOrEqual(2, $historyQueries);
    }
}
