<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Events\ActivityLogged;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Core\Models\PropertyHistory;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

/**
 * Cockpit scheduling (#110), lead-status history (#111), completing sequence steps (#112) and input checks (#119).
 */
class SalesCockpitActionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array{step: int, type: string, delay_days: int, title: string}>  $steps
     */
    private function enrollment(Contact $contact, array $steps, string $status = 'active'): SalesSequenceEnrollment
    {
        $sequence = SalesSequence::query()->create(['name' => 'Outbound', 'is_active' => true, 'steps' => $steps]);

        return SalesSequenceEnrollment::query()->create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'current_step' => 1,
            'status' => $status,
            'next_step_due_at' => now(),
            'enrolled_at' => now()->subDay(),
        ]);
    }

    public function test_scheduling_without_a_contact_asks_for_one_instead_of_failing(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(SalesCockpit::class)
            ->call('openMeetingModal')
            ->set('meetingTitle', 'Pipeline review')
            ->call('saveMeetingLog')
            ->assertHasErrors(['meetingContactId'])
            ->assertSet('showMeetingModal', true);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_scheduling_with_a_picked_contact_logs_the_meeting_through_the_action(): void
    {
        $contact = Contact::factory()->create(['first_name' => 'Ada', 'last_name' => 'Lovelace']);
        Contact::factory()->count(3)->create();
        Event::fake([ActivityLogged::class]);

        $component = Livewire::actingAs(User::factory()->create())
            ->test(SalesCockpit::class)
            ->call('openMeetingModal')
            ->set('meetingContactSearch', 'lovel');

        $this->assertSame([$contact->id], array_keys($component->get('meetingContactOptions')));

        $component
            ->set('meetingContactId', $contact->id)
            ->call('saveMeetingLog')
            ->assertHasNoErrors()
            ->assertSet('showMeetingModal', false);

        $this->assertTrue(Activity::query()
            ->where('subject_type', $contact->getMorphClass())
            ->where('subject_id', $contact->id)
            ->where('type', ActivityType::Meeting)
            ->exists());
        Event::assertDispatched(ActivityLogged::class);
    }

    public function test_malformed_dates_are_validation_errors(): void
    {
        $contact = Contact::factory()->create();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SalesCockpit::class)
            ->call('openMeetingModal', $contact->id)
            ->set('meetingDate', 'not-a-date')
            ->set('meetingTime', '25:99')
            ->call('saveMeetingLog')
            ->assertHasErrors(['meetingDate', 'meetingTime']);

        Livewire::actingAs($user)
            ->test(SalesCockpit::class)
            ->call('openCallModal', $contact->id)
            ->set('createFollowUpTask', true)
            ->set('followUpTaskDate', 'next tuesday-ish')
            ->call('saveCallLog')
            ->assertHasErrors(['followUpTaskDate']);

        $this->assertSame(0, Activity::query()->count());
    }

    public function test_lead_status_changes_from_the_cockpit_are_recorded_in_property_history(): void
    {
        $contact = Contact::factory()->create(['lead_status' => LeadStatus::New]);

        Livewire::actingAs(User::factory()->create())
            ->test(SalesCockpit::class)
            ->call('logQuickTouch', $contact->id, 'email');

        $this->assertSame(LeadStatus::AttemptedContact, $contact->refresh()->lead_status);
        $this->assertTrue(PropertyHistory::query()
            ->where('auditable_id', $contact->id)
            ->where('property_name', 'lead_status')
            ->exists());
    }

    public function test_completing_a_manual_step_logs_it_and_claims_the_step(): void
    {
        $contact = Contact::factory()->create(['lead_status' => LeadStatus::New]);
        $enrollment = $this->enrollment($contact, [
            ['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Intro call'],
            ['step' => 2, 'type' => 'task', 'delay_days' => 2, 'title' => 'Send deck'],
        ]);

        Livewire::actingAs(User::factory()->create())
            ->test(SalesCockpit::class)
            ->call('advanceEnrollment', $enrollment->id)
            ->assertNotified('Sequence Step Advanced');

        $this->assertSame(2, $enrollment->refresh()->current_step);

        /** @var Activity $activity */
        $activity = Activity::query()->where('subject_id', $contact->id)->firstOrFail();
        $this->assertSame(ActivityType::Call, $activity->type);
        $this->assertSame(ActivityStatus::Completed, $activity->status);
        $this->assertSame($enrollment->id, $activity->metadata['sequence_enrollment_id'] ?? null);
        $this->assertSame(1, $activity->metadata['step'] ?? null);
    }

    public function test_completing_a_step_completes_the_pending_task_the_processor_created(): void
    {
        $contact = Contact::factory()->create();
        $enrollment = $this->enrollment($contact, [
            ['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Intro call'],
            ['step' => 2, 'type' => 'task', 'delay_days' => 2, 'title' => 'Send deck'],
        ]);
        $pending = $contact->logActivity(
            type: ActivityType::Call,
            title: 'Call: Intro call',
            metadata: ['sequence_enrollment_id' => $enrollment->id, 'step' => 1],
            status: ActivityStatus::Pending,
        );

        Livewire::actingAs(User::factory()->create())
            ->test(SalesCockpit::class)
            ->call('advanceEnrollment', $enrollment->id);

        $this->assertSame(ActivityStatus::Completed, $pending->refresh()->status);
        $this->assertSame(1, Activity::query()->where('subject_id', $contact->id)->count());
    }

    public function test_an_email_step_is_not_skipped_from_the_cockpit(): void
    {
        $contact = Contact::factory()->create();
        $enrollment = $this->enrollment($contact, [
            ['step' => 1, 'type' => 'email', 'delay_days' => 0, 'title' => 'Intro email'],
            ['step' => 2, 'type' => 'call', 'delay_days' => 2, 'title' => 'Call'],
        ]);

        $component = Livewire::actingAs(User::factory()->create())->test(SalesCockpit::class);

        $action = collect($component->get('guidedActions'))->firstWhere('enrollment_id', $enrollment->id);
        $this->assertNotSame('advance_sequence', $action['action_type'] ?? null);

        $component->call('advanceEnrollment', $enrollment->id)->assertNotified('Step Not Completed');

        $this->assertSame(1, $enrollment->refresh()->current_step);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_an_enrollment_that_is_no_longer_active_is_not_advanced(): void
    {
        $contact = Contact::factory()->create();
        $enrollment = $this->enrollment($contact, [
            ['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Intro call'],
            ['step' => 2, 'type' => 'call', 'delay_days' => 2, 'title' => 'Call again'],
        ], status: 'unenrolled');

        Livewire::actingAs(User::factory()->create())
            ->test(SalesCockpit::class)
            ->call('advanceEnrollment', $enrollment->id)
            ->assertNotified('Step Not Completed');

        $enrollment->refresh();
        $this->assertSame(1, $enrollment->current_step);
        $this->assertSame('unenrolled', $enrollment->status);
    }
}
