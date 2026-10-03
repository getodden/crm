<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Odden\Core\Enums\LifecycleStage;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\CheckFatiguePolicyAction;
use Odden\Marketing\Actions\DecayInactiveLeadScoresAction;
use Odden\Marketing\Actions\DispatchCampaignAction;
use Odden\Marketing\Actions\GetCampaignAttributionAction;
use Odden\Marketing\Enums\AttributionModel;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\CampaignType;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Support\ContactPreferences;

class MarketingProFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_decay_inactive_lead_scores_action_degrades_scores_and_demotes_lifecycle_stage(): void
    {
        // Contact active recently (10 days ago) - should NOT decay
        $recentContact = Contact::create([
            'first_name' => 'Recent',
            'last_name' => 'Active',
            'email' => 'recent@example.com',
            'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead,
            'lead_score' => 60,
            'lead_score_updated_at' => now()->subDays(10),
        ]);

        // Contact inactive for 65 days (2 decay periods: floor(65 / 30) = 2 -> 2 * 5 = 10 pts deduction)
        // Score was 55 -> becomes 45 (< 50 threshold -> demoted to Lead)
        $dormantContact = Contact::create([
            'first_name' => 'Dormant',
            'last_name' => 'Mql',
            'email' => 'dormant@example.com',
            'lifecycle_stage' => LifecycleStage::MarketingQualifiedLead,
            'lead_score' => 55,
            'lead_score_updated_at' => now()->subDays(65),
        ]);

        // Contact inactive for 90 days with 15 points (3 decay periods -> 3 * 5 = 15 pts deduction -> 0 pts -> demoted to Subscriber)
        $staleLead = Contact::create([
            'first_name' => 'Stale',
            'last_name' => 'Lead',
            'email' => 'stale@example.com',
            'lifecycle_stage' => LifecycleStage::Lead,
            'lead_score' => 15,
            'lead_score_updated_at' => now()->subDays(90),
        ]);

        $action = new DecayInactiveLeadScoresAction;
        $results = $action->execute(inactivityThresholdDays: 30, decayPointsPerPeriod: 5);

        $this->assertSame(2, $results['decayed_contacts_count']);
        $this->assertSame(25, $results['total_points_decayed']); // 10 + 15

        // Verify recent contact untouched
        $recentContact->refresh();
        $this->assertSame(60, $recentContact->lead_score);
        $this->assertSame(LifecycleStage::MarketingQualifiedLead, $recentContact->lifecycle_stage);

        // Verify dormant contact decayed and demoted
        $dormantContact->refresh();
        $this->assertSame(45, $dormantContact->lead_score);
        $this->assertSame(LifecycleStage::Lead, $dormantContact->lifecycle_stage);

        // Verify stale contact decayed to 0 and demoted to Subscriber
        $staleLead->refresh();
        $this->assertSame(0, $staleLead->lead_score);
        $this->assertSame(LifecycleStage::Subscriber, $staleLead->lifecycle_stage);

        // Verify audit logs were written
        $this->assertDatabaseHas('odden_marketing_lead_decay_logs', [
            'contact_id' => $dormantContact->id,
            'score_before' => 55,
            'score_after' => 45,
            'score_decayed' => 10,
        ]);
        $this->assertDatabaseHas('odden_marketing_lead_score_logs', [
            'contact_id' => $dormantContact->id,
            'score_change' => -10,
        ]);
    }

    public function test_decay_lead_scores_artisan_command_executes_successfully(): void
    {
        Contact::create([
            'first_name' => 'Inactive',
            'last_name' => 'Person',
            'email' => 'inactive@example.com',
            'lifecycle_stage' => LifecycleStage::Lead,
            'lead_score' => 25,
            'lead_score_updated_at' => now()->subDays(45),
        ]);

        $exitCode = Artisan::call('marketing:decay-lead-scores', [
            '--days' => 30,
            '--points' => 5,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertDatabaseHas('odden_marketing_lead_decay_logs', [
            'score_before' => 25,
            'score_after' => 20,
            'score_decayed' => 5,
        ]);
    }

    public function test_campaign_attribution_calculates_roi_and_cost_per_lead(): void
    {
        $campaign = Campaign::create([
            'name' => 'Q3 Enterprise Search Campaign',
            'subject' => 'Find more deals faster',
            'sender_name' => 'Odden',
            'sender_email' => 'growth@odden.test',
            'status' => CampaignStatus::Sent,
            'type' => CampaignType::Regular,
            'budget' => 5000.00,
            'actual_cost' => 4000.00,
            'total_recipients' => 100,
            'delivered_count' => 100,
        ]);

        $action = new GetCampaignAttributionAction;
        $attribution = $action->execute($campaign, AttributionModel::FirstTouch);

        // With zero revenue, net profit is negative actual cost
        $this->assertSame(5000.0, $attribution['budget']);
        $this->assertSame(4000.0, $attribution['actual_cost']);
        $this->assertSame(-4000.0, $attribution['net_profit']);
        $this->assertSame(-100.0, $attribution['roi_percentage']);
        $this->assertSame(0.0, $attribution['cost_per_lead']);
    }

    public function test_fatigue_policy_prevents_over_messaging_contacts(): void
    {
        $contact = Contact::create([
            'first_name' => 'Busy',
            'last_name' => 'Executive',
            'email' => 'executive@example.com',
            'last_marketing_email_sent_at' => now()->subHours(6), // Sent 6 hours ago
        ]);

        $action = new CheckFatiguePolicyAction;

        // Policy allows max 2 per 7 days, minimum 24 hours between sends
        $result = $action->execute($contact);
        $this->assertFalse($result['can_send']);
        $this->assertStringContainsString('minimum interval', (string) $result['reason']);

        // Push last sent back 48 hours, but create 2 recipient logs in past 7 days
        $contact->update(['last_marketing_email_sent_at' => now()->subHours(48)]);

        $campaign = Campaign::create([
            'name' => 'Previous Campaign',
            'subject' => 'Hello',
            'sender_name' => 'Odden',
            'sender_email' => 'test@odden.test',
            'status' => CampaignStatus::Sent,
            'type' => CampaignType::Regular,
        ]);

        CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => 'sent',
            'tracking_token' => Str::random(40),
            'unsubscribe_token' => Str::random(40),
            'sent_at' => now()->subDays(2),
        ]);
        // A second campaign: a contact is a recipient of a campaign at most once.
        $earlierCampaign = Campaign::create([
            'name' => 'Earlier Campaign',
            'subject' => 'Hello',
            'sender_name' => 'Odden',
            'sender_email' => 'test@odden.test',
            'status' => CampaignStatus::Sent,
            'type' => CampaignType::Regular,
        ]);
        CampaignRecipient::create([
            'campaign_id' => $earlierCampaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => 'sent',
            'tracking_token' => Str::random(40),
            'unsubscribe_token' => Str::random(40),
            'sent_at' => now()->subDays(4),
        ]);

        // Now has 2 sends in 7 days -> weekly cap reached
        $weeklyCapResult = $action->execute($contact);
        $this->assertFalse($weeklyCapResult['can_send']);
        $this->assertStringContainsString('weekly communication cap', (string) $weeklyCapResult['reason']);

        // Contact with no recent sends should be allowed
        $freshContact = Contact::create([
            'first_name' => 'Fresh',
            'last_name' => 'Contact',
            'email' => 'fresh@example.com',
        ]);
        $freshResult = $action->execute($freshContact);
        $this->assertTrue($freshResult['can_send']);
        $this->assertNull($freshResult['reason']);
    }

    public function test_dispatch_campaign_filters_by_topic_subscription(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Product News',
            'subject' => 'New Product Feature',
            'body_html' => '<p>Hello {{contact.first_name}}</p>',
            'body_text' => 'Hello {{contact.first_name}}',
        ]);

        // Contact 1 subscribed to product_updates
        $optedIn = Contact::create([
            'first_name' => 'Opted',
            'last_name' => 'In',
            'email' => 'optedin@example.com',
            'marketing_topics' => ['product_updates', 'newsletter'],
        ]);

        // Contact 2 only subscribed to webinars (not product_updates)
        $optedOut = Contact::create([
            'first_name' => 'Opted',
            'last_name' => 'Out',
            'email' => 'optedout@example.com',
            'marketing_topics' => ['webinars'],
        ]);

        $campaign = Campaign::create([
            'name' => 'Product Updates Release',
            'subject' => 'Major v2.0 update',
            'sender_name' => 'Odden',
            'sender_email' => 'news@odden.test',
            'template_id' => $template->id,
            'topic' => 'product_updates',
            'status' => CampaignStatus::Draft,
            'type' => CampaignType::Regular,
        ]);

        $dispatchAction = new DispatchCampaignAction;
        $stats = $dispatchAction->execute($campaign, collect([$optedIn, $optedOut]));

        $this->assertSame(1, $stats['delivered_count']);
        $this->assertSame(1, $stats['suppressed_count']); // Contact 2 suppressed due to topic preference

        // Check delivered contact updated last_marketing_email_sent_at
        $optedIn->refresh();
        $this->assertNotNull($optedIn->last_marketing_email_sent_at);

        $optedOut->refresh();
        $this->assertNull($optedOut->last_marketing_email_sent_at);
    }

    public function test_subscriber_preference_center_updates_topic_subscriptions_and_opt_out(): void
    {
        $verificationToken = Str::random(40);
        $contact = Contact::create([
            'first_name' => 'Taylor',
            'last_name' => 'Otwell',
            'email' => 'taylor@example.com',
            'marketing_verification_token' => $verificationToken,
            'marketing_topics' => ['newsletter', 'product_updates'],
        ]);

        // 1. Visit preference center
        $response = $this->get("/marketing/preferences/{$verificationToken}");
        $response->assertOk();
        $response->assertSee('Preferences');
        $response->assertSee('taylor@example.com');

        // 2. Update topics to webinars and security
        $postResponse = $this->post("/marketing/preferences/{$verificationToken}", [
            'topics' => ['webinars', 'security'],
        ]);
        $postResponse->assertRedirect();

        $contact->refresh();
        $this->assertSame(['webinars', 'security'], $contact->marketing_topics);
        $this->assertTrue(ContactPreferences::isSubscribedToTopic($contact, 'webinars'));
        $this->assertFalse(ContactPreferences::isSubscribedToTopic($contact, 'newsletter'));

        // 3. Global opt-out
        $optOutResponse = $this->post("/marketing/preferences/{$verificationToken}", [
            'opt_out_all' => '1',
        ]);
        $optOutResponse->assertRedirect();

        $this->assertTrue(MarketingSubscription::isSuppressed('taylor@example.com'));
        $contact->refresh();
        $this->assertSame([], $contact->marketing_topics);
    }

    public function test_double_opt_in_email_verification_marks_verified_and_awards_points(): void
    {
        $verificationToken = Str::random(40);
        $contact = Contact::create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@example.com',
            'lead_score' => 10,
            'marketing_confirmation_token' => $verificationToken,
            'marketing_email_verified_at' => null,
        ]);

        // Confirmation GET endpoint
        $response = $this->get("/marketing/confirm/{$verificationToken}");
        $response->assertOk();
        $response->assertSee('Subscription Confirmed!');

        $contact->refresh();
        $this->assertNotNull($contact->marketing_email_verified_at);
        $this->assertSame(30, $contact->lead_score); // 10 + 20 bonus points awarded

        // Calling again does not duplicate bonus points
        $response2 = $this->get("/marketing/confirm/{$verificationToken}");
        $response2->assertOk();

        $contact->refresh();
        $this->assertSame(30, $contact->lead_score);
    }
}
