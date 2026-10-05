<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Resources\DealResource\Pages\KanbanDeals;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\DealStatus;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

class LivewireAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_move_deal_is_forbidden_when_policy_denies_update(): void
    {
        $this->denyAbilities([Deal::class], ['update']);
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);
        $deal = Deal::factory()->create([
            'pipeline_id' => $pipeline->id,
            'stage_id' => $pipeline->stages[0]->id,
            'status' => DealStatus::Open,
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(KanbanDeals::class)
            ->call('moveDeal', $deal->id, $pipeline->stages[3]->id)
            ->assertForbidden();

        $this->assertSame($pipeline->stages[0]->id, $deal->fresh()?->stage_id);
    }

    public function test_move_deal_returns_404_for_unknown_deal(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);

        Livewire::actingAs(User::factory()->create())
            ->test(KanbanDeals::class)
            ->call('moveDeal', 999999, $pipeline->stages[1]->id)
            ->assertNotFound();
    }

    public function test_merge_contacts_is_forbidden_when_policy_denies_delete(): void
    {
        $this->denyAbilities([Contact::class], ['delete']);
        $primary = Contact::factory()->create(['email' => 'dupe@example.com']);
        $secondary = Contact::factory()->create(['email' => 'dupe2@example.com']);

        Livewire::actingAs(User::factory()->create())
            ->test(DataQuality::class)
            ->call('mergeContacts', $primary->id, $secondary->id)
            ->assertForbidden();

        $this->assertNotNull(Contact::query()->find($secondary->id));
    }

    public function test_merge_companies_is_forbidden_when_policy_denies_update(): void
    {
        $this->denyAbilities([Company::class], ['update']);
        $primary = Company::factory()->create(['name' => 'Acme']);
        $secondary = Company::factory()->create(['name' => 'Acme Inc']);

        Livewire::actingAs(User::factory()->create())
            ->test(DataQuality::class)
            ->call('mergeCompanies', $primary->id, $secondary->id)
            ->assertForbidden();

        $this->assertNotNull(Company::query()->find($secondary->id));
    }

    public function test_sales_cockpit_methods_are_forbidden_when_policy_denies_update(): void
    {
        $this->denyAbilities([Contact::class, Activity::class], ['update']);
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['lead_status' => LeadStatus::New]);

        $sequence = SalesSequence::query()->create([
            'name' => 'Outreach',
            'is_active' => true,
            'steps' => [
                ['step' => 1, 'type' => 'email', 'delay_days' => 0, 'title' => 'Pitch'],
                ['step' => 2, 'type' => 'call', 'delay_days' => 2, 'title' => 'Follow up'],
            ],
        ]);
        $enrollment = SalesSequenceEnrollment::query()->create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'current_step' => 1,
            'status' => 'active',
            'next_step_due_at' => now(),
        ]);
        $activity = Activity::query()->create([
            'type' => ActivityType::Task,
            'title' => 'Call back',
            'status' => ActivityStatus::Pending,
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
        ]);

        Livewire::actingAs($user)->test(SalesCockpit::class)
            ->call('advanceEnrollment', $enrollment->id)->assertForbidden();
        Livewire::actingAs($user)->test(SalesCockpit::class)
            ->call('completeActivity', $activity->id)->assertForbidden();
        Livewire::actingAs($user)->test(SalesCockpit::class)
            ->call('logQuickTouch', $contact->id, 'call')->assertForbidden();
        Livewire::actingAs($user)->test(SalesCockpit::class)
            ->call('openCallModal', $contact->id)->assertForbidden();
        Livewire::actingAs($user)->test(SalesCockpit::class)
            ->set('meetingContactId', $contact->id)
            ->call('saveMeetingLog')->assertForbidden();
        Livewire::actingAs($user)->test(SalesCockpit::class)
            ->set('callContactId', $contact->id)
            ->call('saveCallLog')->assertForbidden();

        $this->assertSame(1, $enrollment->fresh()?->current_step);
        $this->assertSame(ActivityStatus::Pending, $activity->fresh()?->status);
        $this->assertSame(LeadStatus::New, $contact->fresh()?->lead_status);
        $this->assertSame(1, Activity::query()->count());
    }

    public function test_methods_still_work_when_a_policy_allows(): void
    {
        $this->denyAbilities([Contact::class], []);
        $user = User::factory()->create();
        $primary = Contact::factory()->create(['email' => 'a@example.com']);
        $secondary = Contact::factory()->create(['email' => 'b@example.com']);

        Livewire::actingAs($user)
            ->test(DataQuality::class)
            ->call('mergeContacts', $primary->id, $secondary->id)
            ->assertSuccessful();

        $this->assertNull(Contact::query()->find($secondary->id));
    }

    public function test_completing_an_activity_requires_update_on_the_record_it_belongs_to(): void
    {
        // No Activity policy: the activity's contact decides.
        $this->denyAbilities([Contact::class], ['update']);
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $activity = Activity::query()->create([
            'type' => ActivityType::Task,
            'title' => 'Call back',
            'status' => ActivityStatus::Pending,
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
        ]);

        Livewire::actingAs($user)->test(SalesCockpit::class)
            ->call('completeActivity', $activity->id)->assertForbidden();

        $this->assertSame(ActivityStatus::Pending, $activity->fresh()->status);
    }
}
