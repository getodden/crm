<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Filament\Resources\ContactResource\Pages\EditContact;
use Odden\Filament\Resources\ContactResource\RelationManagers\SalesSequenceEnrollmentsRelationManager;
use Odden\Filament\Resources\RelationManagers\ActivitiesRelationManager;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

class PanelActionsReturnNothingTest extends TestCase
{
    use RefreshDatabase;

    public function test_completing_an_activity_stores_it_and_sends_no_record_to_the_browser(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $activity = Activity::create([
            'subject_type' => $contact->getMorphClass(),
            'subject_id' => $contact->getKey(),
            'type' => 'task',
            'title' => 'Secret follow-up',
            'body' => 'Private notes',
            'status' => ActivityStatus::Pending,
        ]);

        $component = Livewire::actingAs($user)
            ->test(ActivitiesRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
            ->callAction(TestAction::make('complete')->table($activity));

        $this->assertSame(ActivityStatus::Completed, $activity->fresh()->status);
        $this->assertNotNull($activity->fresh()->completed_at);
        $this->assertSame([null], $component->effects['returns'] ?? []);
    }

    public function test_unenrolling_stores_it_and_sends_no_record_to_the_browser(): void
    {
        $user = User::factory()->create();
        $contact = Contact::factory()->create();
        $sequence = SalesSequence::create([
            'name' => 'Secret cadence',
            'steps' => [['step' => 1, 'type' => 'call', 'delay_days' => 0, 'title' => 'Call']],
            'is_active' => true,
        ]);
        $enrollment = SalesSequenceEnrollment::create([
            'sequence_id' => $sequence->id,
            'contact_id' => $contact->id,
            'status' => 'active',
            'next_step_due_at' => now()->addDay(),
        ]);

        $component = Livewire::actingAs($user)
            ->test(SalesSequenceEnrollmentsRelationManager::class, ['ownerRecord' => $contact, 'pageClass' => EditContact::class])
            ->callAction(TestAction::make('unenroll')->table($enrollment));

        $this->assertSame('unenrolled', $enrollment->fresh()->status);
        $this->assertNull($enrollment->fresh()->next_step_due_at);
        $this->assertSame([null], $component->effects['returns'] ?? []);
    }
}
