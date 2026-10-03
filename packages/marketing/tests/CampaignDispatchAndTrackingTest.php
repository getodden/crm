<?php

declare(strict_types=1);

namespace Odden\Marketing\Tests;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Marketing\Actions\CompileCampaignMessageAction;
use Odden\Marketing\Actions\DispatchCampaignAction;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingSubscription;
use Odden\Marketing\Models\MarketingTemplate;

class CampaignDispatchAndTrackingTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_compile_campaign_with_merge_tags_pixel_and_click_tracking(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Newsletter Edition #1',
            'subject' => 'Product Updates',
            'body_html' => '<html><body><p>Hello {{contact.first_name}}, welcome to {{company.name}}!</p><a href="https://odden.test/pricing">Check Pricing</a></body></html>',
        ]);

        $campaign = Campaign::create([
            'name' => 'Q1 Product Launch',
            'subject' => 'Major updates are here',
            'sender_name' => 'Odden Team',
            'sender_email' => 'news@odden.test',
            'template_id' => $template->id,
            'status' => CampaignStatus::Draft,
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'G-Man',
            'last_name' => 'Observer',
            'email' => 'gman@unforeseen.test',
        ]);

        $company = Company::create(['name' => 'Black Mesa Overseers']);
        $contact->associateWith($company);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Pending,
        ]);

        $html = (new CompileCampaignMessageAction)->execute($campaign, $recipient);

        $this->assertStringContainsString('Hello G-Man', $html);
        $this->assertStringContainsString('welcome to Black Mesa Overseers', $html);
        $this->assertStringContainsString('/marketing/track/click/'.$recipient->tracking_token, $html);
        $this->assertStringContainsString('/marketing/track/open/'.$recipient->tracking_token, $html);
    }

    public function test_can_dispatch_campaign_and_skip_suppressed_emails(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Simple Blast',
            'subject' => 'Big Announcement',
            'body_html' => '<p>Hello {{contact.first_name}}!</p>',
        ]);

        $campaign = Campaign::create([
            'name' => 'March Newsletter',
            'subject' => 'Our new features',
            'sender_name' => 'Odden Team',
            'sender_email' => 'news@odden.test',
            'template_id' => $template->id,
            'status' => CampaignStatus::Draft,
        ]);

        $contactActive = Contact::factory()->create([
            'first_name' => 'Barney',
            'email' => 'barney@blackmesa.test',
        ]);

        $contactSuppressed = Contact::factory()->create([
            'first_name' => 'Breen',
            'email' => 'breen@citadel.test',
        ]);

        // Suppress Breen
        MarketingSubscription::unsubscribe('breen@citadel.test', $contactSuppressed->id);

        $contacts = new Collection([$contactActive, $contactSuppressed]);

        $results = (new DispatchCampaignAction)->execute($campaign, $contacts);

        $this->assertSame(2, $results['total_recipients']);
        $this->assertSame(1, $results['delivered_count']);
        $this->assertSame(1, $results['suppressed_count']);

        $campaign->refresh();
        $this->assertSame(CampaignStatus::Sent, $campaign->status);
        $this->assertNotNull($campaign->sent_at);
        $this->assertSame(1, $campaign->delivered_count);

        $this->assertDatabaseHas('odden_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'email' => 'barney@blackmesa.test',
        ]);

        $this->assertDatabaseMissing('odden_marketing_campaign_recipients', [
            'campaign_id' => $campaign->id,
            'email' => 'breen@citadel.test',
        ]);
    }

    public function test_open_tracking_pixel_increments_open_count(): void
    {
        $campaign = Campaign::create([
            'name' => 'Open Test',
            'subject' => 'Testing opens',
            'sender_name' => 'Odden',
            'sender_email' => 'odden@test.com',
            'status' => CampaignStatus::Sent,
            'delivered_count' => 10,
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'email' => 'test@example.com',
            'status' => RecipientStatus::Sent,
        ]);

        $response = $this->get('/marketing/track/open/'.$recipient->tracking_token);

        $response->assertSuccessful();
        $response->assertHeader('Content-Type', 'image/gif');

        $recipient->refresh();
        $campaign->refresh();

        $this->assertSame(RecipientStatus::Opened, $recipient->status);
        $this->assertNotNull($recipient->opened_at);
        $this->assertSame(1, $campaign->opens_count);
        $this->assertSame(1, $campaign->unique_opens_count);
        $this->assertSame(10.0, $campaign->open_rate);
    }

    public function test_click_tracking_records_click_and_redirects(): void
    {
        $campaign = Campaign::create([
            'name' => 'Click Test',
            'subject' => 'Testing clicks',
            'sender_name' => 'Odden',
            'sender_email' => 'odden@test.com',
            'status' => CampaignStatus::Sent,
            'delivered_count' => 10,
        ]);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'email' => 'test@example.com',
            'status' => RecipientStatus::Sent,
        ]);

        $targetUrl = 'https://odden.test/special-offer';
        $response = $this->get($recipient->getClickRedirectUrl($targetUrl));

        $response->assertRedirect($targetUrl);

        $recipient->refresh();
        $campaign->refresh();

        $this->assertSame(RecipientStatus::Clicked, $recipient->status);
        $this->assertNotNull($recipient->clicked_at);
        $this->assertSame(1, $campaign->clicks_count);
        $this->assertSame(1, $campaign->unique_clicks_count);
        $this->assertSame(10.0, $campaign->click_rate);
    }

    public function test_recipient_can_unsubscribe_and_is_suppressed(): void
    {
        $campaign = Campaign::create([
            'name' => 'Unsub Test',
            'subject' => 'Unsubscribe Test',
            'sender_name' => 'Odden',
            'sender_email' => 'odden@test.com',
            'status' => CampaignStatus::Sent,
        ]);

        $contact = Contact::factory()->create(['email' => 'unsub@domain.test']);

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Sent,
        ]);

        $showResponse = $this->get('/marketing/unsubscribe/'.$recipient->unsubscribe_token);
        $showResponse->assertSuccessful();
        $showResponse->assertSee('unsub@domain.test');

        $postResponse = $this->post('/marketing/unsubscribe/'.$recipient->unsubscribe_token);
        $postResponse->assertSuccessful();
        $postResponse->assertSee('Unsubscribed');

        $recipient->refresh();
        $campaign->refresh();

        $this->assertSame(RecipientStatus::Unsubscribed, $recipient->status);
        $this->assertSame(1, $campaign->unsubscribes_count);
        $this->assertTrue(MarketingSubscription::isSuppressed('unsub@domain.test'));
    }

    public function test_recipient_status_only_moves_forward(): void
    {
        $campaign = Campaign::create([
            'name' => 'Forward Only',
            'subject' => 'Status',
            'sender_name' => 'Odden',
            'sender_email' => 'odden@test.com',
            'status' => CampaignStatus::Sent,
        ]);

        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id, 'email' => 'fwd@example.com', 'status' => RecipientStatus::Sent]);

        $this->get($recipient->getClickRedirectUrl('https://odden.test/offer'))->assertRedirect();
        $this->get($recipient->getTrackingPixelUrl())->assertOk();

        $recipient->refresh();
        $this->assertSame(RecipientStatus::Clicked, $recipient->status);
        $this->assertNotNull($recipient->opened_at, 'The open is still recorded');
        $this->assertSame(1, $campaign->fresh()->opens_count);

        $bounced = CampaignRecipient::create(['campaign_id' => $campaign->id, 'email' => 'bounced@example.com', 'status' => RecipientStatus::Bounced]);
        $this->get($bounced->getTrackingPixelUrl())->assertOk();
        $this->assertSame(RecipientStatus::Bounced, $bounced->fresh()->status);
    }

    public function test_campaigns_fill_every_registered_merge_tag_with_filters_and_escaping(): void
    {
        $template = MarketingTemplate::create([
            'name' => 'Merge Tags',
            'subject' => 'Hi',
            'body_html' => '<p>{{ contact.first_name | upper }} | {{contact.full_name}} | {{contact.job_title}} | {{company.domain}} | {{company.industry}} | {{sender.name}} | {{sender.email}} | {{contact.phone}} | {{contact.lifecycle_stage}} | {{ campaign.name }} | {{contact.missing_thing}}</p>',
        ]);

        $campaign = Campaign::create([
            'name' => 'Merge <Run>',
            'subject' => 'Subject',
            'sender_name' => 'Odden Team',
            'sender_email' => 'news@odden.test',
            'template_id' => $template->id,
            'status' => CampaignStatus::Draft,
        ]);

        $contact = Contact::factory()->create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
            'email' => 'sarah@example.com',
            'job_title' => 'VP <script>alert(1)</script> Ops',
            'phone' => '+1 555 0100',
        ]);
        $contact->associateWith(Company::create(['name' => 'Cyberdyne', 'domain' => 'cyberdyne.test', 'industry' => 'Robotics']));

        $recipient = CampaignRecipient::create([
            'campaign_id' => $campaign->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'status' => RecipientStatus::Pending,
        ]);

        $html = (new CompileCampaignMessageAction)->execute($campaign, $recipient);

        $this->assertStringContainsString('SARAH | Sarah Connor | VP &lt;script&gt;alert(1)&lt;/script&gt; Ops | cyberdyne.test | Robotics | Odden Team | news@odden.test | +1 555 0100 |', $html);
        $this->assertStringContainsString('| Merge &lt;Run&gt; |', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('{{contact.job_title}}', $html);
        // Tags nobody registered stay as written.
        $this->assertStringContainsString('{{contact.missing_thing}}', $html);
    }

    public function test_campaign_emails_can_carry_the_contacts_signed_rsvp_token(): void
    {
        $event = MarketingEvent::create(['title' => 'Spring Summit', 'slug' => 'spring-summit', 'event_type' => 'webinar']);
        $other = MarketingEvent::create(['title' => 'Other', 'slug' => 'other', 'event_type' => 'webinar']);

        $template = MarketingTemplate::create([
            'name' => 'RSVP',
            'subject' => 'Join us',
            'body_html' => '<form><input type="hidden" name="token" value="{{event.'.$event->id.'.rsvp_token}}"></form> {{ event.'.$other->id.'.rsvp_token }} {{event.9999.rsvp_token}}',
        ]);
        $campaign = Campaign::create(['name' => 'Summit invite', 'subject' => 'Join us', 'sender_name' => 'Odden', 'sender_email' => 'news@odden.test', 'template_id' => $template->id, 'status' => CampaignStatus::Draft]);
        $contact = Contact::factory()->create();
        $recipient = CampaignRecipient::create(['campaign_id' => $campaign->id, 'contact_id' => $contact->id, 'email' => $contact->email, 'status' => RecipientStatus::Pending]);

        $html = (new CompileCampaignMessageAction)->execute($campaign, $recipient);

        $token = $event->rsvpTokenFor($contact);
        $this->assertStringContainsString('value="'.$token.'"', $html);
        $this->assertStringContainsString($other->rsvpTokenFor($contact), $html);
        $this->assertStringContainsString('{{event.9999.rsvp_token}}', $html, 'Unknown events are left as written');

        // The token it issues is accepted by the AMP RSVP endpoint for that event only.
        $amp = $this->withHeaders(['Origin' => 'https://mail.google.com']);
        $amp->postJson('/api/marketing/amp/rsvp', ['event_slug' => 'spring-summit', 'token' => $token])->assertOk();
        $amp->postJson('/api/marketing/amp/rsvp', ['event_slug' => 'other', 'token' => $token])->assertForbidden();
    }
}
