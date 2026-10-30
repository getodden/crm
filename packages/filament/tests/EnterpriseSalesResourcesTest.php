<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Carbon\CarbonInterface;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Odden\Filament\Resources\SalesMeetingLinkResource\Pages\CreateSalesMeetingLink;
use Odden\Filament\Resources\SalesMeetingLinkResource\Pages\EditSalesMeetingLink;
use Odden\Filament\Resources\SalesMeetingLinkResource\Pages\ListSalesMeetingLinks;
use Odden\Filament\Resources\SalesMeetingLinkResource\RelationManagers\BookingsRelationManager;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Sales\Database\Seeders\SalesDatabaseSeeder;
use Odden\Sales\Enums\LeadRoutingStrategy;
use Odden\Sales\Events\MeetingBookingCancelled;
use Odden\Sales\Mail\MeetingCancelledMail;
use Odden\Sales\Models\Deal;
use Odden\Sales\Models\LeadRoutingRule;
use Odden\Sales\Models\Pipeline;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Models\SalesMeetingLink;
use Odden\Sales\Models\SalesPlaybook;
use Odden\Sales\Models\SalesSequence;

class EnterpriseSalesResourcesTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_sales_sequences_index(): void
    {
        $user = User::factory()->create();
        SalesSequence::create([
            'name' => 'High-Velocity Outbound',
            'steps' => [
                ['step' => 1, 'type' => 'automated_email', 'delay_days' => 0, 'title' => 'Intro Email'],
            ],
            'is_active' => true,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->get('/admin/sales-sequences');

        $response->assertSuccessful();
        $response->assertSee('High-Velocity Outbound');
    }

    public function test_authenticated_user_can_access_sales_playbooks_index(): void
    {
        $user = User::factory()->create();
        SalesPlaybook::create(array_merge(SalesPlaybook::defaultBantPreset(), [
            'user_id' => $user->id,
        ]));

        $response = $this->actingAs($user)->get('/admin/sales-playbooks');

        $response->assertSuccessful();
        $response->assertSee('BANT Qualification Playbook');
    }

    public function test_authenticated_user_can_access_meeting_links_index(): void
    {
        $user = User::factory()->create();
        SalesMeetingLink::create([
            'user_id' => $user->id,
            'title' => 'Demo Strategy Session',
            'slug' => 'demo-strategy-session',
            'duration_minutes' => 30,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/admin/sales-meeting-links');

        $response->assertSuccessful();
        $response->assertSee('Demo Strategy Session');
    }

    public function test_meeting_link_form_saves_working_hours_buffer_and_timezone(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateSalesMeetingLink::class)
            ->fillForm([
                'user_id' => $user->id,
                'title' => 'Discovery Call',
                'slug' => 'discovery-call',
                'duration_minutes' => 30,
                'buffer_minutes' => 15,
                'timezone' => 'Europe/London',
                'working_hours' => [
                    'monday' => ['09:00-12:00', '13:00-17:00'],
                    'wednesday' => [' 10:00-16:00 '],
                    'friday' => [],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $link = SalesMeetingLink::query()->where('slug', 'discovery-call')->firstOrFail();

        $this->assertSame(15, $link->buffer_minutes);
        $this->assertSame('Europe/London', $link->timezone);
        $this->assertSame([
            'monday' => ['09:00-12:00', '13:00-17:00'],
            'wednesday' => ['10:00-16:00'],
        ], $link->working_hours);

        Livewire::actingAs($user)
            ->test(EditSalesMeetingLink::class, ['record' => $link->getRouteKey()])
            ->assertFormSet([
                'buffer_minutes' => 15,
                'timezone' => 'Europe/London',
                'working_hours.monday' => ['09:00-12:00', '13:00-17:00'],
            ])
            ->fillForm([
                'buffer_minutes' => 0,
                'timezone' => null,
                'working_hours' => array_fill_keys(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'], []),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $link->refresh();

        $this->assertSame(0, $link->buffer_minutes);
        $this->assertNull($link->timezone);
        $this->assertNull($link->working_hours);
    }

    public function test_meeting_link_form_rejects_malformed_working_hours_and_unknown_timezones(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(CreateSalesMeetingLink::class)
            ->fillForm([
                'user_id' => $user->id,
                'title' => 'Discovery Call',
                'slug' => 'discovery-call',
                'duration_minutes' => 30,
                'buffer_minutes' => -5,
                'timezone' => 'Mars/Olympus_Mons',
                'working_hours' => [
                    'monday' => ['9am-5pm'],
                    'tuesday' => ['17:00-09:00'],
                ],
            ])
            ->call('create')
            ->assertHasFormErrors([
                'buffer_minutes',
                'timezone',
                'working_hours.monday.0',
                'working_hours.tuesday.0',
            ]);

        $this->assertFalse(SalesMeetingLink::query()->where('slug', 'discovery-call')->exists());
    }

    public function test_meeting_link_with_an_upcoming_booking_cannot_be_deleted_from_the_edit_page_or_table(): void
    {
        $user = User::factory()->create();
        $link = $this->meetingLinkWithBooking($user, 'busy-link', now()->addDay());

        Livewire::actingAs($user)
            ->test(EditSalesMeetingLink::class, ['record' => $link->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('This link has 1 upcoming booking. Cancel them, or wait until they have passed. Turn the link off to stop new bookings.');

        Livewire::actingAs($user)
            ->test(ListSalesMeetingLinks::class)
            ->callAction(TestAction::make('delete')->table($link))
            ->assertNotified('This link has 1 upcoming booking. Cancel them, or wait until they have passed. Turn the link off to stop new bookings.');

        $this->assertTrue(SalesMeetingLink::query()->whereKey($link->id)->exists());
        $this->assertSame(1, SalesMeetingBooking::query()->count());
    }

    public function test_meeting_link_without_upcoming_bookings_can_be_deleted_from_the_edit_page(): void
    {
        $user = User::factory()->create();
        $link = $this->meetingLinkWithBooking($user, 'quiet-link', now()->subDay());

        Livewire::actingAs($user)
            ->test(EditSalesMeetingLink::class, ['record' => $link->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Deleted');

        $this->assertFalse(SalesMeetingLink::query()->whereKey($link->id)->exists());
    }

    public function test_bulk_delete_skips_links_with_upcoming_bookings_and_reports_how_many(): void
    {
        $user = User::factory()->create();
        $busyOne = $this->meetingLinkWithBooking($user, 'busy-one', now()->addDay());
        $busyTwo = $this->meetingLinkWithBooking($user, 'busy-two', now()->addDays(2));
        $quiet = $this->meetingLinkWithBooking($user, 'quiet-one', now()->subDay());

        Livewire::actingAs($user)
            ->test(ListSalesMeetingLinks::class)
            ->selectTableRecords([$busyOne->id, $busyTwo->id, $quiet->id])
            ->callAction(TestAction::make('delete')->table()->bulk())
            ->assertNotified('Skipped 2 meeting links with upcoming bookings');

        $this->assertTrue(SalesMeetingLink::query()->whereKey([$busyOne->id, $busyTwo->id])->count() === 2);
        $this->assertFalse(SalesMeetingLink::query()->whereKey($quiet->id)->exists());
    }

    public function test_staff_can_cancel_an_upcoming_booking_from_the_link_page_and_both_parties_are_emailed(): void
    {
        Mail::fake();
        Event::fake([MeetingBookingCancelled::class]);
        $user = User::factory()->create(['name' => 'Beth Caldwell', 'email' => 'beth@example.com']);
        $link = $this->meetingLinkWithBooking($user, 'cancel-me', now()->addDay());
        $booking = $link->bookings()->firstOrFail();

        Livewire::actingAs($user)
            ->test(BookingsRelationManager::class, ['ownerRecord' => $link, 'pageClass' => EditSalesMeetingLink::class])
            ->assertCanSeeTableRecords([$booking])
            ->callAction(TestAction::make('cancelBooking')->table($booking), ['reason' => 'Out sick'])
            ->assertNotified('Booking cancelled');

        $this->assertNotNull($booking->fresh()?->cancelled_at);
        Event::assertDispatchedTimes(MeetingBookingCancelled::class, 1);
        Event::assertDispatched(MeetingBookingCancelled::class, fn (MeetingBookingCancelled $event): bool => $event->reason === 'Out sick' && $event->actorName === 'Beth Caldwell' && $event->uid === $booking->uid);
    }

    public function test_cancelling_from_the_link_page_sends_the_invitee_and_host_emails_once(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'beth@example.com']);
        $link = $this->meetingLinkWithBooking($user, 'mail-me', now()->addDay());
        $booking = $link->bookings()->firstOrFail();

        $component = Livewire::actingAs($user)
            ->test(BookingsRelationManager::class, ['ownerRecord' => $link, 'pageClass' => EditSalesMeetingLink::class])
            ->callAction(TestAction::make('cancelBooking')->table($booking), ['reason' => 'Out sick']);

        Mail::assertQueued(MeetingCancelledMail::class, 2);
        Mail::assertQueued(MeetingCancelledMail::class, fn (MeetingCancelledMail $mail): bool => $mail->hasTo('dana@example.com') && str_contains($mail->render(), 'Out sick'));
        Mail::assertQueued(MeetingCancelledMail::class, fn (MeetingCancelledMail $mail): bool => $mail->hasTo('beth@example.com'));
        $component->assertTableActionHidden('cancelBooking', $booking);
    }

    public function test_cancel_booking_is_hidden_for_past_and_cancelled_bookings(): void
    {
        $user = User::factory()->create();
        $past = $this->meetingLinkWithBooking($user, 'past-link', now()->subDay())->bookings()->firstOrFail();
        $cancelled = $this->meetingLinkWithBooking($user, 'done-link', now()->addDay())->bookings()->firstOrFail();
        $cancelled->cancel();

        foreach ([$past, $cancelled] as $booking) {
            Livewire::actingAs($user)
                ->test(BookingsRelationManager::class, ['ownerRecord' => $booking->meetingLink, 'pageClass' => EditSalesMeetingLink::class])
                ->assertTableActionHidden('cancelBooking', $booking);
        }
    }

    public function test_cancel_booking_is_hidden_and_does_nothing_when_policy_denies_updating_the_link(): void
    {
        $this->denyAbilities([SalesMeetingLink::class], ['update']);
        Mail::fake();
        $user = User::factory()->create();
        $link = $this->meetingLinkWithBooking($user, 'locked-link', now()->addDay());
        $booking = $link->bookings()->firstOrFail();

        Livewire::actingAs($user)
            ->test(BookingsRelationManager::class, ['ownerRecord' => $link, 'pageClass' => EditSalesMeetingLink::class])
            ->assertTableActionHidden('cancelBooking', $booking)
            ->mountAction(TestAction::make('cancelBooking')->table($booking))
            ->callMountedAction();

        $this->assertNull($booking->fresh()?->cancelled_at);
        Mail::assertNotQueued(MeetingCancelledMail::class);
    }

    public function test_bookings_list_upcoming_first(): void
    {
        $user = User::factory()->create();
        $link = $this->meetingLinkWithBooking($user, 'ordered', now()->addDays(3));
        $past = $this->booking($link, now()->subDays(2));
        $soon = $this->booking($link, now()->addDay());
        $later = $link->bookings()->where('starts_at', '>', now()->addDays(2))->firstOrFail();

        Livewire::actingAs($user)
            ->test(BookingsRelationManager::class, ['ownerRecord' => $link, 'pageClass' => EditSalesMeetingLink::class])
            ->assertCanSeeTableRecords([$soon, $later, $past], inOrder: true);
    }

    private function booking(SalesMeetingLink $link, CarbonInterface $startsAt): SalesMeetingBooking
    {
        return SalesMeetingBooking::query()->create([
            'uid' => (string) Str::uuid(),
            'meeting_link_id' => $link->id,
            'user_id' => $link->user_id,
            'invitee_name' => 'Pat',
            'invitee_email' => 'pat@example.com',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'timezone' => 'UTC',
        ]);
    }

    private function meetingLinkWithBooking(User $user, string $slug, CarbonInterface $startsAt): SalesMeetingLink
    {
        $link = SalesMeetingLink::query()->create([
            'user_id' => $user->id,
            'title' => 'Call '.$slug,
            'slug' => $slug,
            'duration_minutes' => 30,
            'is_active' => true,
        ]);

        SalesMeetingBooking::query()->create([
            'uid' => (string) Str::uuid(),
            'meeting_link_id' => $link->id,
            'user_id' => $user->id,
            'invitee_name' => 'Dana',
            'invitee_email' => 'dana@example.com',
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes(30),
            'timezone' => 'UTC',
        ]);

        return $link;
    }

    public function test_authenticated_user_can_access_lead_routing_rules_index(): void
    {
        $user = User::factory()->create();
        LeadRoutingRule::create([
            'name' => 'Round Robin All Inbound',
            'strategy' => LeadRoutingStrategy::RoundRobin,
            'assigned_user_ids' => [$user->id],
            'sort_order' => 1,
            'is_active' => true,
        ]);

        $response = $this->actingAs($user)->get('/admin/lead-routing-rules');

        $response->assertSuccessful();
        $response->assertSee('Round Robin All Inbound');
    }

    public function test_sales_database_seeder_populates_enterprise_features(): void
    {
        $pipeline = Pipeline::factory()->withStages()->create(['is_default' => true]);

        $this->seed(SalesDatabaseSeeder::class);

        $this->assertDatabaseHas('odden_sales_playbooks', ['slug' => 'bant-qualification']);
        $this->assertDatabaseHas('odden_sales_playbooks', ['slug' => 'meddic-enterprise']);
        $this->assertDatabaseHas('odden_sales_sequences', ['name' => 'Enterprise Outbound 14-Day Cadence']);
        $this->assertDatabaseHas('odden_sales_meeting_links', ['slug' => 'beth-caldwell']);
        $this->assertDatabaseHas('odden_sales_lead_routing_rules', ['name' => 'Inbound Enterprise Round Robin']);

        // Verify seeded deals with health scores
        $deals = Deal::where('pipeline_id', $pipeline->id)->get();
        $this->assertNotEmpty($deals);
        foreach ($deals as $deal) {
            $health = $deal->getHealthScore();
            $this->assertGreaterThanOrEqual(0, $health['score']);
            $this->assertLessThanOrEqual(100, $health['score']);
            $this->assertNotEmpty($health['badge_label']);
            $this->assertIsArray($health['factors']);
        }
    }
}
