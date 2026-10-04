<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\MarketingSubscriptionTopic;
use Odden\Marketing\Support\ContactPreferences;

class MarketingSubscriptionTopicTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_topic_and_evaluate_default_subscription(): void
    {
        $topic = MarketingSubscriptionTopic::create([
            'name' => 'Product Updates',
            'slug' => 'product_updates',
            'description' => 'Changelogs and new feature releases',
            'is_default' => true,
        ]);

        $this->assertTrue(MarketingSubscriptionTopic::isSubscribed('lead@example.com', $topic->id));
        $this->assertFalse(MarketingSubscription::isSuppressed('lead@example.com', $topic->id));
    }

    public function test_selective_topic_unsubscribing(): void
    {
        $topicMarketing = MarketingSubscriptionTopic::create([
            'name' => 'Promotional Offers',
            'slug' => 'promos',
            'is_default' => true,
        ]);

        $topicSecurity = MarketingSubscriptionTopic::create([
            'name' => 'Security Bulletins',
            'slug' => 'security',
            'is_default' => true,
        ]);

        // Unsubscribe only from promos
        MarketingSubscriptionTopic::setSubscription('user@acme.com', $topicMarketing->id, false);

        // Promos should be suppressed
        $this->assertTrue(MarketingSubscription::isSuppressed('user@acme.com', $topicMarketing->id));
        $this->assertFalse(MarketingSubscriptionTopic::isSubscribed('user@acme.com', $topicMarketing->id));

        // Security bulletins should NOT be suppressed
        $this->assertFalse(MarketingSubscription::isSuppressed('user@acme.com', $topicSecurity->id));
        $this->assertTrue(MarketingSubscriptionTopic::isSubscribed('user@acme.com', $topicSecurity->id));
    }

    public function test_global_unsubscribe_overrides_all_topics(): void
    {
        $topic = MarketingSubscriptionTopic::create([
            'name' => 'Weekly Newsletter',
            'slug' => 'newsletter',
            'is_default' => true,
        ]);

        // Contact opted in to topic
        MarketingSubscriptionTopic::setSubscription('optout@acme.com', $topic->id, true);

        // But globally unsubscribed
        MarketingSubscription::unsubscribe('optout@acme.com');

        // Both general and topic checks must report suppressed
        $this->assertTrue(MarketingSubscription::isSuppressed('optout@acme.com'));
        $this->assertTrue(MarketingSubscription::isSuppressed('optout@acme.com', $topic->id));
    }

    public function test_preference_center_updates_topic_subscriptions(): void
    {
        $contact = Contact::create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@cyberdyne.test',
            'marketing_verification_token' => 'pref_tok_999',
        ]);

        $topic1 = MarketingSubscriptionTopic::create(['name' => 'Digest', 'slug' => 'digest', 'is_default' => true]);
        $topic2 = MarketingSubscriptionTopic::create(['name' => 'Promos', 'slug' => 'promos', 'is_default' => true]);

        // Submit preferences selecting only 'digest'
        $response = $this->post('/marketing/preferences/pref_tok_999', [
            'topics' => ['digest'],
        ]);

        $response->assertRedirect();

        $this->assertTrue(MarketingSubscriptionTopic::isSubscribed('sarah@cyberdyne.test', $topic1->id));
        $this->assertFalse(MarketingSubscriptionTopic::isSubscribed('sarah@cyberdyne.test', $topic2->id));
    }

    public function test_preference_center_refuses_malformed_topics_instead_of_crashing(): void
    {
        Contact::create(['first_name' => 'Sam', 'last_name' => 'Doe', 'email' => 'sam@example.com', 'marketing_verification_token' => 'pref_tok_bad']);
        $topic = MarketingSubscriptionTopic::create(['name' => 'Digest', 'slug' => 'digest', 'is_default' => true]);
        MarketingSubscriptionTopic::setSubscription('sam@example.com', $topic->id, true);

        // A string, or a nested array, used to throw a TypeError (a 500) from in_array().
        $this->post('/marketing/preferences/pref_tok_bad', ['topics' => 'digest'])->assertSessionHasErrors('topics');
        $this->post('/marketing/preferences/pref_tok_bad', ['topics' => [['x']]])->assertSessionHasErrors('topics.0');

        $this->assertTrue(MarketingSubscriptionTopic::isSubscribed('sam@example.com', $topic->id), 'A refused request changes nothing');
    }

    public function test_preference_center_returns_404_for_an_unknown_token(): void
    {
        $this->get('/marketing/preferences/not-a-real-token')->assertNotFound();
    }

    public function test_viewing_the_preference_center_does_not_create_topics(): void
    {
        Contact::create(['first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'pat@example.com', 'marketing_verification_token' => 'pref_tok_view']);

        $this->get('/marketing/preferences/pref_tok_view')->assertOk();

        $this->assertSame(0, MarketingSubscriptionTopic::query()->count());

        MarketingSubscriptionTopic::seedDefaults();
        $this->assertSame(4, MarketingSubscriptionTopic::query()->count());

        MarketingSubscriptionTopic::seedDefaults();
        $this->assertSame(4, MarketingSubscriptionTopic::query()->count(), 'Seeding twice is a no-op');
    }

    public function test_double_opt_in_uses_its_own_token(): void
    {
        $contact = Contact::create(['first_name' => 'Pat', 'last_name' => 'Doe', 'email' => 'pat2@example.com']);

        $preferenceUrl = ContactPreferences::preferenceCenterUrl($contact);
        $confirmUrl = ContactPreferences::confirmationUrl($contact);
        $contact->refresh();

        $this->assertNotSame($contact->marketing_verification_token, $contact->marketing_confirmation_token);

        // The preference token can't confirm the address, and the confirmation token isn't a preference link.
        $this->get('/marketing/confirm/'.$contact->marketing_verification_token)->assertNotFound();
        $this->assertNull($contact->fresh()->marketing_email_verified_at);
        $this->get('/marketing/preferences/'.$contact->marketing_confirmation_token)->assertNotFound();

        $this->get($confirmUrl)->assertOk();
        $this->assertNotNull($contact->fresh()->marketing_email_verified_at);
        $this->assertStringEndsWith($contact->marketing_verification_token, $preferenceUrl);
    }
}
