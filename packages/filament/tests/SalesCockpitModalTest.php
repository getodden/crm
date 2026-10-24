<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Enums\LeadStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Filament\Pages\SalesCockpit;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Enums\CallDisposition;

class SalesCockpitModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_open_call_modal_and_save_call_log_with_disposition(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create([
            'first_name' => 'Miles',
            'last_name' => 'Morales',
            'lead_status' => LeadStatus::New,
        ]);

        Livewire::actingAs($user)
            ->test(SalesCockpit::class)
            ->call('openCallModal', $contact->id)
            ->assertSet('showCallModal', true)
            ->assertSet('callContactId', $contact->id)
            ->set('callDisposition', CallDisposition::Connected->value)
            ->set('callDurationMinutes', 12)
            ->set('callNotes', 'Discussed enterprise pricing tier.')
            ->set('createFollowUpTask', true)
            ->set('followUpTaskDate', now()->addDays(2)->toDateString())
            ->set('followUpTaskTitle', 'Send revised quote proposal')
            ->call('saveCallLog')
            ->assertSet('showCallModal', false);

        $contact->refresh();
        $this->assertSame(LeadStatus::Connected, $contact->lead_status);
        $this->assertNotNull($contact->last_contacted_at);

        $this->assertDatabaseHas('odden_activities', [
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
            'type' => ActivityType::Call->value,
        ]);

        $this->assertTrue(Activity::query()->where([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
            'type' => ActivityType::Task->value,
        ])->get()->contains(fn (Activity $row): bool => $row->title == 'Send revised quote proposal'));
    }

    public function test_can_open_meeting_modal_and_schedule_meeting(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();

        Livewire::actingAs($user)
            ->test(SalesCockpit::class)
            ->call('openMeetingModal', $contact->id)
            ->assertSet('showMeetingModal', true)
            ->set('meetingTitle', 'Quarterly Pipeline Sync')
            ->set('meetingDate', now()->addDay()->toDateString())
            ->set('meetingTime', '14:30')
            ->set('meetingDurationMinutes', 45)
            ->set('meetingNotes', 'Zoom link in calendar invite.')
            ->call('saveMeetingLog')
            ->assertSet('showMeetingModal', false);

        $this->assertTrue(Activity::query()->where([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->id,
            'type' => ActivityType::Meeting->value,
        ])->get()->contains(fn (Activity $row): bool => $row->title == 'Quarterly Pipeline Sync'));
    }
}
