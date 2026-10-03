<?php

declare(strict_types=1);

namespace Odden\Core\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Odden\Core\Actions\LogActivityAction;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Events\ActivityLogged;
use Odden\Core\Models\Contact;
use Odden\Core\Tests\Fixtures\User;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_log_notes_and_calls_on_contact(): void
    {
        $contact = Contact::factory()->create();

        $note = $contact->logNote('Met at conference, interested in demo.');
        $call = $contact->logCall('Discovery Call', 'Discussed pricing and team size.', ['duration_seconds' => 300]);

        $this->assertSame(ActivityType::Note, $note->type);
        $this->assertSame('Met at conference, interested in demo.', $note->body);

        $this->assertSame(ActivityType::Call, $call->type);
        $this->assertSame(300, $call->metadata['duration_seconds']);

        $activities = $contact->activities;
        $this->assertCount(2, $activities);
    }

    public function test_can_log_task_with_due_date(): void
    {
        $contact = Contact::factory()->create();
        $dueDate = now()->addDays(3);

        $task = $contact->logTask('Follow up on proposal', $dueDate);

        $this->assertSame(ActivityType::Task, $task->type);
        $this->assertSame(ActivityStatus::Pending, $task->status);
        $this->assertNotNull($task->due_at);
        $this->assertNull($task->completed_at);
    }

    public function test_log_activity_action_dispatches_event(): void
    {
        Event::fake([ActivityLogged::class]);

        $contact = Contact::factory()->create();

        $action = new LogActivityAction;
        $action->execute(
            subject: $contact,
            type: ActivityType::Meeting,
            title: 'Q3 Business Review',
            body: 'Reviewed pipeline forecast.'
        );

        Event::assertDispatched(ActivityLogged::class);
    }

    public function test_every_logging_helper_dispatches_the_event_and_sets_the_creator(): void
    {
        Event::fake([ActivityLogged::class]);

        $user = User::factory()->create();
        $this->actingAs($user);
        $contact = Contact::factory()->create();

        $contact->logNote('A note');
        $contact->logCall('A call');
        $contact->logTask('A task');
        $contact->logActivity(ActivityType::Meeting, 'A meeting');

        Event::assertDispatchedTimes(ActivityLogged::class, 4);
        $this->assertSame([$user->id], $contact->activities()->pluck('creator_id')->unique()->all());
    }

    public function test_whatsapp_and_sms_activities_can_be_logged(): void
    {
        $contact = Contact::factory()->create();

        $whatsapp = $contact->logActivity('whatsapp', 'Sent a WhatsApp message');
        $sms = $contact->logActivity(ActivityType::Sms, 'Sent an SMS');

        $this->assertSame(ActivityType::WhatsApp, $whatsapp->fresh()->type);
        $this->assertSame(ActivityType::Sms, $sms->fresh()->type);
        $this->assertSame('WhatsApp', ActivityType::WhatsApp->label());
        $this->assertSame('SMS', ActivityType::Sms->label());
    }
}
