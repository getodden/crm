<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Actions\EvaluateActiveListAction;
use Odden\Core\Enums\ListType;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Marketing\Actions\RegisterContactForEventAction;
use Odden\Marketing\Actions\TrackAssetDownloadAction;
use Odden\Marketing\Actions\UpdateAttendanceStatusAction;
use Odden\Marketing\Enums\WorkflowStepType;
use Odden\Marketing\Enums\WorkflowTriggerType;
use Odden\Marketing\Models\MarketingAsset;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingWorkflow;

class AssetsAndEventsMarketingTest extends TestCase
{
    use RefreshDatabase;

    public function test_gated_asset_download_tracking_scoring_and_workflow_trigger(): void
    {
        $contact = Contact::create([
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'email' => 'ghopper@navy.mil',
            'lead_score' => 10,
        ]);

        $asset = MarketingAsset::create([
            'name' => '2026 Enterprise SaaS Pricing Report',
            'asset_type' => 'whitepaper',
            'is_gated' => true,
            'lead_score_points' => 15,
            'external_url' => 'https://example.com/reports/saas-2026.pdf',
        ]);

        // Create an automated workflow listening for downloaded asset
        $workflow = MarketingWorkflow::create([
            'name' => 'Post-Whitepaper Nurture Sequence',
            'trigger_type' => WorkflowTriggerType::AssetDownloaded,
            'trigger_config' => ['asset_id' => $asset->id],
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'type' => WorkflowStepType::SendEmail,
            'config' => [
                'subject' => 'Thanks for reading our 2026 Benchmark Report',
                'body' => 'Hi {{contact.first_name}}, here are additional insights!',
            ],
        ]);

        $tracker = app(TrackAssetDownloadAction::class);
        $download = $tracker->execute(
            asset: $asset,
            contact: $contact,
            ipAddress: '127.0.0.1',
            userAgent: 'Mozilla/5.0'
        );

        $this->assertNotNull($download);
        $asset->refresh();
        $this->assertSame(1, $asset->downloads_count);
        $this->assertSame(1, $asset->unique_leads_count);

        // Verify lead scoring bonus applied (+15 points => 10 + 15 = 25)
        $contact->refresh();
        $this->assertSame(25, $contact->lead_score);

        // Verify task logged on contact timeline
        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $contact->id,
            'title' => 'Downloaded Asset: 2026 Enterprise SaaS Pricing Report',
        ]);

        // Verify contact enrolled in nurture workflow
        $this->assertDatabaseHas('odden_marketing_workflow_enrollments', [
            'workflow_id' => $workflow->id,
            'contact_id' => $contact->id,
        ]);

        // Verify HTTP download route redirects to external URL
        $response = $this->get($asset->getDownloadUrl($contact));

        $response->assertRedirect('https://example.com/reports/saas-2026.pdf');
        $asset->refresh();
        $this->assertSame(2, $asset->downloads_count);
        $this->assertSame(1, $asset->unique_leads_count); // Same contact, unique leads stays 1
    }

    public function test_event_registration_and_attendance_lifecycle(): void
    {
        $contact = Contact::create([
            'first_name' => 'Alan',
            'last_name' => 'Turing',
            'email' => 'turing@bletchley.uk',
            'lead_score' => 20,
        ]);

        $event = MarketingEvent::create([
            'title' => 'Building Autonomous AI Agents at Scale',
            'event_type' => 'webinar',
            'starts_at' => now()->addDays(3),
            'virtual_meeting_url' => 'https://zoom.us/j/123456789',
        ]);

        // Workflow for event attendees
        $workflow = MarketingWorkflow::create([
            'name' => 'Post-Webinar Deck & Rep Follow-Up',
            'trigger_type' => WorkflowTriggerType::EventAttended,
            'trigger_config' => ['event_id' => $event->id],
            'is_active' => true,
        ]);

        $workflow->steps()->create([
            'step_number' => 1,
            'type' => WorkflowStepType::SendEmail,
            'config' => [
                'subject' => 'Webinar Slides & Recording Access',
                'body' => 'Here are the slides from today!',
            ],
        ]);

        // 1. Register contact
        $registerAction = app(RegisterContactForEventAction::class);
        $reg = $registerAction->execute($event, $contact, [
            'utm_source' => 'linkedin',
            'utm_campaign' => 'spring_webinar',
        ]);

        $this->assertSame('registered', $reg->status);
        $this->assertSame('linkedin', $reg->utm_source);

        $event->refresh();
        $this->assertSame(1, $event->registrations_count);
        $this->assertSame(0, $event->attendees_count);

        $contact->refresh();
        // +10 points for registering
        $this->assertSame(30, $contact->lead_score);
        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $contact->id,
            'title' => 'Registered for Event: Building Autonomous AI Agents at Scale',
        ]);

        // 2. Mark Attendance (e.g. via attendance action or Zoom webhook)
        $attendanceAction = app(UpdateAttendanceStatusAction::class);
        $attendanceAction->execute($reg, 'attended');

        $reg->refresh();
        $this->assertSame('attended', $reg->status);
        $this->assertNotNull($reg->attended_at);

        $event->refresh();
        $this->assertSame(1, $event->attendees_count);
        $this->assertSame(100.0, $event->attendanceRate());

        $contact->refresh();
        // +20 points for attending live (+30 + 20 = 50)
        $this->assertSame(50, $contact->lead_score);
        $this->assertDatabaseHas('odden_activities', [
            'subject_id' => $contact->id,
            'title' => 'Attended Event: Building Autonomous AI Agents at Scale',
        ]);

        // Enrolled in attended workflow
        $this->assertDatabaseHas('odden_marketing_workflow_enrollments', [
            'workflow_id' => $workflow->id,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_event_public_api_endpoints(): void
    {
        $event = MarketingEvent::create([
            'title' => 'Quarterly Product Briefing',
            'slug' => 'quarterly-briefing',
            'event_type' => 'webinar',
            'starts_at' => now()->addDays(7),
            'virtual_meeting_url' => 'https://meet.google.com/abc-def-ghi',
        ]);

        // Test API registration
        $postData = [
            'email' => 'newlead@enterprise.com',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'utm_source' => 'google_ads',
        ];

        $res = $this->postJson(route('odden.marketing.events.register', ['slug' => $event->slug]), $postData);
        $res->assertStatus(200);
        $res->assertJsonPath('success', true);
        $res->assertJsonPath('event.title', 'Quarterly Product Briefing');

        $newContact = Contact::where('email', 'newlead@enterprise.com')->first();
        $this->assertNotNull($newContact);
        $this->assertSame('Jane', $newContact->first_name);

        // Test attendance webhook (e.g. from Zoom / Meet)
        $webhookRes = $this->postJson(route('odden.marketing.events.attendance-webhook', ['slug' => $event->slug]), [
            'email' => 'newlead@enterprise.com',
            'status' => 'attended',
        ]);

        $webhookRes->assertStatus(200);
        $webhookRes->assertJsonPath('status', 'attended');

        $event->refresh();
        $this->assertSame(1, $event->attendees_count);
    }

    public function test_dynamic_active_list_behavioral_segmentation(): void
    {
        $contactA = Contact::create(['first_name' => 'Lead A', 'email' => 'a@test.com']);
        $contactB = Contact::create(['first_name' => 'Lead B', 'email' => 'b@test.com']);

        $asset = MarketingAsset::create([
            'name' => 'Security Whitepaper',
            'asset_type' => 'whitepaper',
        ]);

        // Contact A downloads the asset
        app(TrackAssetDownloadAction::class)->execute($asset, $contactA);

        // Dynamic active list for contacts who downloaded any asset
        $assetList = CrmList::create([
            'name' => 'Asset Downloaders Cohort',
            'entity_type' => 'contact',
            'type' => ListType::Active,
            'criteria' => [
                ['property' => 'has_downloaded_asset', 'operator' => '=', 'value' => true],
            ],
        ]);

        $action = new EvaluateActiveListAction;
        $matched = $action->execute($assetList);

        $this->assertSame(1, $matched);
        $this->assertTrue($assetList->hasMember($contactA));
        $this->assertFalse($assetList->hasMember($contactB));

        // Now test webinar attendance cohort
        $event = MarketingEvent::create(['title' => 'Roadmap AMA']);
        $regB = app(RegisterContactForEventAction::class)->execute($event, $contactB);
        app(UpdateAttendanceStatusAction::class)->execute($regB, 'attended');

        $webinarList = CrmList::create([
            'name' => 'Live Webinar Attendees',
            'entity_type' => 'contact',
            'type' => ListType::Active,
            'criteria' => [
                ['property' => 'has_attended_event', 'operator' => '=', 'value' => true],
            ],
        ]);

        $matchedWebinar = $action->execute($webinarList);
        $this->assertSame(1, $matchedWebinar);
        $this->assertTrue($webinarList->hasMember($contactB));
        $this->assertFalse($webinarList->hasMember($contactA));
    }

    public function test_registration_requires_a_published_open_event_with_room(): void
    {
        $url = fn (MarketingEvent $event): string => route('odden.marketing.events.register', ['slug' => $event->slug]);
        $payload = ['email' => 'guest@example.com'];

        $unpublished = MarketingEvent::create(['title' => 'Hidden', 'slug' => 'hidden', 'event_type' => 'webinar', 'is_published' => false]);
        $this->postJson($url($unpublished), $payload)->assertNotFound();

        foreach (['draft', 'completed', 'cancelled'] as $status) {
            $closed = MarketingEvent::create(['title' => "Event {$status}", 'slug' => "event-{$status}", 'event_type' => 'webinar', 'status' => $status]);
            $this->postJson($url($closed), $payload)->assertStatus(409);
        }

        $live = MarketingEvent::create(['title' => 'Live', 'slug' => 'live-event', 'event_type' => 'webinar', 'status' => 'live']);
        $this->postJson($url($live), $payload)->assertOk();

        $small = MarketingEvent::create(['title' => 'Small', 'slug' => 'small-event', 'event_type' => 'webinar', 'capacity' => 1]);
        $this->postJson($url($small), ['email' => 'first@example.com'])->assertOk();
        $this->postJson($url($small), ['email' => 'second@example.com'])->assertStatus(409);

        // Someone already registered can re-submit even when the event is full.
        $this->postJson($url($small), ['email' => 'FIRST@example.com'])->assertOk();
        $this->assertSame(1, $small->fresh()->registrations_count);
    }

    public function test_registration_matches_contacts_case_insensitively(): void
    {
        $existing = Contact::factory()->create(['email' => 'ana@example.com']);
        $event = MarketingEvent::create(['title' => 'Briefing', 'slug' => 'briefing', 'event_type' => 'webinar']);

        $this->postJson(route('odden.marketing.events.register', ['slug' => $event->slug]), ['email' => ' Ana@Example.COM '])->assertOk();

        $this->assertSame(1, Contact::query()->whereRaw('LOWER(email) = ?', ['ana@example.com'])->count());
        $this->assertDatabaseHas('odden_marketing_event_registrations', ['event_id' => $event->id, 'contact_id' => $existing->id]);
    }

    public function test_registering_again_keeps_an_attended_registration_attended(): void
    {
        $event = MarketingEvent::create(['title' => 'Briefing', 'slug' => 'briefing-attended', 'event_type' => 'webinar']);
        $contact = Contact::factory()->create();

        $registration = app(RegisterContactForEventAction::class)->execute($event, $contact);
        app(UpdateAttendanceStatusAction::class)->execute($registration, 'attended');

        $again = app(RegisterContactForEventAction::class)->execute($event->fresh(), $contact);

        $this->assertSame('attended', $again->fresh()->status);
        $this->assertSame(1, $event->fresh()->registrations_count);
    }

    public function test_attendance_webhook_validates_the_status(): void
    {
        $event = MarketingEvent::create(['title' => 'Briefing', 'slug' => 'briefing-webhook', 'event_type' => 'webinar']);
        $contact = Contact::factory()->create(['email' => 'zed@example.com']);
        app(RegisterContactForEventAction::class)->execute($event, $contact);

        $url = route('odden.marketing.events.attendance-webhook', ['slug' => $event->slug]);

        $this->postJson($url, ['email' => 'zed@example.com', 'status' => 'teleported'])->assertStatus(422);
        $this->postJson($url, ['email' => 'ZED@example.com', 'status' => 'no_show'])->assertOk()->assertJsonPath('status', 'no_show');
    }
}
