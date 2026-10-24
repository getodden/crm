<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Odden\Core\Enums\ActivityStatus;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Contact;
use Odden\Filament\Pages\DataQuality;
use Odden\Filament\Support\OddenAuthorization;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Models\SalesSequence;
use Odden\Sales\Models\SalesSequenceEnrollment;

/**
 * A model global scope stands in for the tenant or user scoping a host app applies through a resource's
 * `getEloquentQuery()`: pages and widgets must show only what that query allows.
 */
class PageScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Registered per test: the framework clears booted models (and their scopes) between tests.
        Contact::addGlobalScope('page-scoping-test', function (Builder $query): void {
            if (config('page-scoping-test.enabled')) {
                $query->whereBlindExactNotIn('last_name', ['Hidden']);
            }
        });

        config(['page-scoping-test.enabled' => true]);
    }

    protected function tearDown(): void
    {
        config(['page-scoping-test.enabled' => false]);

        parent::tearDown();
    }

    public function test_activities_helper_hides_activities_on_records_the_user_cannot_see(): void
    {
        $visible = Contact::factory()->create(['last_name' => 'Visible']);
        $hidden = Contact::factory()->create(['last_name' => 'Hidden']);

        foreach ([$visible, $hidden] as $contact) {
            $contact->logActivity(type: ActivityType::Task, title: "Task for {$contact->last_name}", status: ActivityStatus::Pending);
        }

        $titles = OddenAuthorization::activities()->pluck('title')->all();

        $this->assertSame(['Task for Visible'], $titles);
    }

    public function test_enrollments_helper_hides_enrollments_of_contacts_the_user_cannot_see(): void
    {
        $sequence = SalesSequence::query()->create([
            'name' => 'Outbound',
            'is_active' => true,
            'steps' => [['step' => 1, 'type' => 'email', 'delay_days' => 0, 'title' => 'Intro']],
        ]);

        $visible = Contact::factory()->create(['last_name' => 'Visible']);
        $hidden = Contact::factory()->create(['last_name' => 'Hidden']);

        foreach ([$visible, $hidden] as $contact) {
            SalesSequenceEnrollment::query()->create([
                'sequence_id' => $sequence->id,
                'contact_id' => $contact->id,
                'current_step' => 1,
                'status' => 'active',
                'next_step_due_at' => now(),
            ]);
        }

        $this->assertSame([$visible->id], OddenAuthorization::enrollments()->pluck('contact_id')->all());
    }

    public function test_data_quality_does_not_report_duplicates_of_hidden_contacts(): void
    {
        $user = User::factory()->create();

        Contact::factory()->create(['email' => 'shared@scoped.test', 'first_name' => 'A', 'last_name' => 'Visible']);
        Contact::factory()->create(['email' => 'shared@scoped.test', 'first_name' => 'B', 'last_name' => 'Hidden']);

        Livewire::actingAs($user)
            ->test(DataQuality::class)
            ->assertDontSee('shared@scoped.test');
    }
}
