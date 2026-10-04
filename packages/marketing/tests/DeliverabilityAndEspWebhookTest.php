<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Enums\SubscriptionStatus;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\MarketingSubscription;

class DeliverabilityAndEspWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_sendgrid_bounce_webhook_suppresses_email_and_increments_campaign_bounces(): void
    {
        $contact = Contact::create([
            'first_name' => 'Bounced',
            'last_name' => 'User',
            'email' => 'bad-email@example.com',
        ]);

        $campaign = Campaign::create([
            'name' => 'Fall Product Launch',
            'subject' => 'New Release Announcement',
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
            'status' => CampaignStatus::Sending,
            'bounces_count' => 0,
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'sg_tok_123',
            'unsubscribe_token' => 'sg_unsub_123',
            'status' => RecipientStatus::Sent,
        ]);

        $response = $this->postJson('/marketing/webhooks/esp/sendgrid', [
            'email' => 'bad-email@example.com',
            'event' => 'bounce',
            'status' => '5.1.1',
            'reason' => '550 5.1.1 Mailbox does not exist',
            'odden_token' => 'sg_tok_123',
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'received',
            'event_type' => 'bounce',
        ]);

        $recipient->refresh();
        $this->assertSame(RecipientStatus::Bounced, $recipient->status);

        $campaign->refresh();
        $this->assertSame(1, $campaign->bounces_count);

        $subscription = MarketingSubscription::where('email', 'bad-email@example.com')->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Bounced, $subscription->status);
        $this->assertTrue(MarketingSubscription::isSuppressed('bad-email@example.com'));
    }

    public function test_a_redelivered_bounce_is_counted_and_scored_once(): void
    {
        $contact = Contact::create(['first_name' => 'Retry', 'email' => 'retry@example.com', 'lead_score' => 80]);
        $campaign = Campaign::create([
            'name' => 'Retry test',
            'subject' => 'Hello',
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
            'status' => CampaignStatus::Sending,
        ]);
        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'retry_tok',
            'unsubscribe_token' => 'retry_unsub',
            'status' => RecipientStatus::Sent,
        ]);

        $bounce = ['email' => 'retry@example.com', 'event' => 'bounce', 'status' => '5.1.1', 'odden_token' => 'retry_tok'];

        // Providers deliver at least once, so the same event can arrive several times.
        foreach (range(1, 3) as $ignored) {
            $this->postJson('/marketing/webhooks/esp/sendgrid', $bounce)->assertOk();
        }

        $this->assertSame(1, $campaign->fresh()?->bounces_count);
        $this->assertSame(30, $contact->fresh()?->lead_score, 'The -50 penalty is applied once');

        // A complaint for the same address afterwards is a different outcome and is counted once as well.
        foreach (range(1, 2) as $ignored) {
            $this->postJson('/marketing/webhooks/esp/sendgrid', ['email' => 'retry@example.com', 'event' => 'spamreport', 'odden_token' => 'retry_tok'])->assertOk();
        }

        $this->assertSame(1, $campaign->fresh()?->unsubscribes_count);
        $this->assertSame(1, $campaign->fresh()?->bounces_count);
    }

    public function test_resend_spam_complaint_webhook_suppresses_email_and_marks_unsubscribed(): void
    {
        $contact = Contact::create([
            'first_name' => 'Complaining',
            'last_name' => 'Customer',
            'email' => 'unhappy@example.com',
        ]);

        $campaign = Campaign::create([
            'name' => 'Newsletter Week 42',
            'subject' => 'Weekly Digest',
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
            'unsubscribes_count' => 0,
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'tracking_token' => 'resend_tok_456',
            'unsubscribe_token' => 'resend_unsub_456',
            'status' => RecipientStatus::Sent,
        ]);

        $response = $this->postJson('/marketing/webhooks/esp/resend', [
            'type' => 'email.complained',
            'data' => [
                'to' => ['unhappy@example.com'],
                'tags' => [
                    'odden_token' => 'resend_tok_456',
                ],
            ],
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'received',
            'event_type' => 'complaint',
        ]);

        $recipient->refresh();
        $this->assertSame(RecipientStatus::Unsubscribed, $recipient->status);

        $campaign->refresh();
        $this->assertSame(1, $campaign->unsubscribes_count);

        $subscription = MarketingSubscription::where('email', 'unhappy@example.com')->first();
        $this->assertNotNull($subscription);
        $this->assertSame(SubscriptionStatus::Unsubscribed, $subscription->status);
        $this->assertTrue(MarketingSubscription::isSuppressed('unhappy@example.com'));
    }

    public function test_unified_deliverability_endpoint_processes_batch_events(): void
    {
        $response = $this->postJson('/api/marketing/webhooks/deliverability', [
            [
                'email' => 'batch1@example.com',
                'event_type' => 'bounce',
                'reason' => 'Host unknown',
            ],
            [
                'email' => 'batch2@example.com',
                'event_type' => 'complaint',
                'reason' => 'Reported spam',
            ],
        ]);

        $response->assertOk();
        $response->assertJson([
            'status' => 'received',
            'count' => 2,
        ]);

        $this->assertTrue(MarketingSubscription::isSuppressed('batch1@example.com'));
        $this->assertTrue(MarketingSubscription::isSuppressed('batch2@example.com'));
    }
}
