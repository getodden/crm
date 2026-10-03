<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Mail\MarketingMessageMailable;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\EmailSuppression;
use Odden\Marketing\Models\EspEvent;
use Odden\Marketing\Models\MarketingSubscription;

class EspProviderEventMappingTest extends TestCase
{
    use RefreshDatabase;

    private function webhook(string $provider, array $payload): TestResponse
    {
        return $this->postJson("/marketing/webhooks/esp/{$provider}", $payload);
    }

    public function test_postmark_complaints_and_hard_bounces_suppress_but_transient_bounces_do_not(): void
    {
        $this->webhook('postmark', ['RecordType' => 'SpamComplaint', 'Email' => 'complainer@example.com'])->assertOk()->assertJsonPath('event_type', 'complaint');
        $this->assertTrue(MarketingSubscription::isSuppressed('complainer@example.com'));

        $this->webhook('postmark', ['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => 'gone@example.com'])->assertJsonPath('event_type', 'hard_bounce');
        $this->assertTrue(MarketingSubscription::isSuppressed('gone@example.com'));

        $this->webhook('postmark', ['RecordType' => 'Bounce', 'Type' => 'Transient', 'Email' => 'full@example.com'])->assertJsonPath('event_type', 'soft_bounce');
        $this->assertFalse(MarketingSubscription::isSuppressed('full@example.com'));
        $this->assertNull(EmailSuppression::query()->where('email', 'full@example.com')->first());
    }

    public function test_events_without_a_type_are_stored_but_never_treated_as_bounces(): void
    {
        $this->webhook('postmark', ['Email' => 'no-type@example.com'])->assertJsonPath('event_type', 'unknown');
        $this->webhook('ses', ['mail' => ['destination' => ['ses-no-type@example.com']]])->assertJsonPath('event_type', 'unknown');
        $this->webhook('generic', ['email' => 'generic-no-type@example.com'])->assertJsonPath('event_type', 'unknown');

        foreach (['no-type@example.com', 'ses-no-type@example.com', 'generic-no-type@example.com'] as $email) {
            $this->assertFalse(MarketingSubscription::isSuppressed($email));
            $this->assertSame(1, EspEvent::query()->where('email', $email)->count());
        }
    }

    public function test_ses_permanent_bounces_suppress_and_transient_ones_do_not(): void
    {
        $this->webhook('ses', [
            'eventType' => 'Bounce',
            'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'perm@example.com']]],
            'mail' => ['destination' => ['perm@example.com']],
        ])->assertJsonPath('event_type', 'hard_bounce');
        $this->assertTrue(MarketingSubscription::isSuppressed('perm@example.com'));

        $this->webhook('ses', [
            'eventType' => 'Bounce',
            'bounce' => ['bounceType' => 'Transient', 'bouncedRecipients' => [['emailAddress' => 'temp@example.com']]],
            'mail' => ['destination' => ['temp@example.com']],
        ])->assertJsonPath('event_type', 'soft_bounce');
        $this->assertFalse(MarketingSubscription::isSuppressed('temp@example.com'));

        $this->webhook('ses', [
            'eventType' => 'Complaint',
            'complaint' => ['complainedRecipients' => [['emailAddress' => 'angry@example.com']]],
            'mail' => ['destination' => ['angry@example.com']],
        ])->assertJsonPath('event_type', 'complaint');
        $this->assertTrue(MarketingSubscription::isSuppressed('angry@example.com'));
    }

    public function test_ses_events_match_the_recipient_by_the_tracking_token_header(): void
    {
        $campaign = Campaign::create(['name' => 'SES', 'subject' => 'SES', 'sender_name' => 'Odden', 'sender_email' => 'news@odden.test']);
        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'email' => 'recipient@example.com',
            'tracking_token' => 'ses_tracking_token_1',
            'unsubscribe_token' => 'ses_unsub_1',
            'status' => RecipientStatus::Sent,
        ]);

        $this->webhook('ses', [
            'eventType' => 'Bounce',
            'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'forwarded@elsewhere.example']]],
            'mail' => ['headers' => [
                ['name' => 'Subject', 'value' => 'Hello'],
                ['name' => MarketingMessageMailable::TRACKING_TOKEN_HEADER, 'value' => 'ses_tracking_token_1'],
            ]],
        ])->assertOk();

        $this->assertSame(RecipientStatus::Bounced, $recipient->fresh()->status);
        $this->assertSame($recipient->id, EspEvent::query()->where('email', 'forwarded@elsewhere.example')->value('recipient_id'));
    }

    public function test_sendgrid_dropped_events_suppress_nobody_and_blocked_bounces_are_soft(): void
    {
        $this->webhook('sendgrid', ['email' => 'dropped@example.com', 'event' => 'dropped', 'reason' => 'Bounced Address'])->assertJsonPath('event_type', 'dropped');
        $this->assertFalse(MarketingSubscription::isSuppressed('dropped@example.com'));

        $this->webhook('sendgrid', ['email' => 'blocked@example.com', 'event' => 'bounce', 'type' => 'blocked'])->assertJsonPath('event_type', 'soft_bounce');
        $this->assertFalse(MarketingSubscription::isSuppressed('blocked@example.com'));

        $this->webhook('sendgrid', ['email' => 'dead@example.com', 'event' => 'bounce', 'type' => 'bounce'])->assertJsonPath('event_type', 'bounce');
        $this->assertTrue(MarketingSubscription::isSuppressed('dead@example.com'));
    }

    public function test_sns_subscription_confirmations_are_confirmed_for_sns_endpoints_only(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        $envelope = ['Type' => 'SubscriptionConfirmation', 'TopicArn' => 'arn:aws:sns:us-east-1:123:ses', 'SubscribeURL' => 'https://sns.us-east-1.amazonaws.com/?Action=ConfirmSubscription&Token=abc'];

        $this->webhook('ses', $envelope)->assertOk()->assertJsonPath('status', 'subscription_confirmed');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://sns.us-east-1.amazonaws.com/'));

        Http::fake();
        $this->webhook('ses', [...$envelope, 'SubscribeURL' => 'https://evil.example.com/?Action=ConfirmSubscription'])->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_sns_notifications_unwrap_the_ses_event(): void
    {
        $message = json_encode([
            'eventType' => 'Bounce',
            'bounce' => ['bounceType' => 'Permanent', 'bouncedRecipients' => [['emailAddress' => 'sns-gone@example.com']]],
            'mail' => ['destination' => ['sns-gone@example.com']],
        ]);

        // SNS posts JSON with a text/plain content type.
        $this->call('POST', '/marketing/webhooks/esp/ses', [], [], [], ['CONTENT_TYPE' => 'text/plain; charset=UTF-8', 'HTTP_X_ODDEN_TOKEN' => self::API_TOKEN], json_encode([
            'Type' => 'Notification',
            'TopicArn' => 'arn:aws:sns:us-east-1:123:ses',
            'Message' => $message,
        ]))->assertOk()->assertJsonPath('event_type', 'hard_bounce');

        $this->assertTrue(MarketingSubscription::isSuppressed('sns-gone@example.com'));

        $this->webhook('ses', ['Type' => 'UnsubscribeConfirmation', 'TopicArn' => 'arn:aws:sns:us-east-1:123:ses'])->assertOk()->assertJsonPath('status', 'ignored');
    }
}
