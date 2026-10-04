<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\KnowledgeArticle;
use Odden\Service\Models\SlaPolicy;
use Odden\Service\Models\Ticket;

class HelpCenterAndPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SlaPolicy::create(SlaPolicy::defaultPreset());
    }

    public function test_can_browse_help_center_and_filter_by_category(): void
    {
        KnowledgeArticle::create([
            'title' => 'Okta SSO Setup Guide',
            'slug' => 'okta-sso-setup',
            'category' => 'Authentication',
            'body' => 'Step by step SAML guide.',
            'is_published' => true,
        ]);

        KnowledgeArticle::create([
            'title' => 'Invoice Payment Methods',
            'slug' => 'invoice-payment-methods',
            'category' => 'Billing',
            'body' => 'Credit card and wire payment options.',
            'is_published' => true,
        ]);

        $response = $this->get('/help');
        $response->assertSuccessful();
        $response->assertSee('Okta SSO Setup Guide');
        $response->assertSee('Invoice Payment Methods');

        // Filter by category
        $filtered = $this->get('/help?category=Authentication');
        $filtered->assertSuccessful();
        $filtered->assertSee('Okta SSO Setup Guide');
        $filtered->assertDontSee('Invoice Payment Methods');
    }

    public function test_can_read_article_and_submit_helpfulness_vote(): void
    {
        $article = KnowledgeArticle::create([
            'title' => 'Webhooks Integration Walkthrough',
            'slug' => 'webhooks-integration',
            'category' => 'API',
            'body' => 'How to verify HMAC signatures.',
            'is_published' => true,
            'views_count' => 0,
            'helpful_count' => 0,
        ]);

        $response = $this->get("/help/{$article->slug}");
        $response->assertSuccessful();
        $response->assertSee('Webhooks Integration Walkthrough');

        $article->refresh();
        $this->assertSame(1, $article->views_count);

        // Submit helpful vote
        $voteResponse = $this->post("/help/{$article->slug}/vote", [
            'type' => 'helpful',
        ]);
        $voteResponse->assertSessionHas('feedback_submitted');

        $article->refresh();
        $this->assertSame(1, $article->helpful_count);
    }

    public function test_an_article_takes_one_vote_per_session_and_another_session_can_vote(): void
    {
        $article = KnowledgeArticle::create([
            'title' => 'Votes',
            'slug' => 'votes',
            'category' => 'API',
            'body' => 'Body.',
            'is_published' => true,
            'helpful_count' => 0,
            'not_helpful_count' => 0,
        ]);

        $this->post("/help/{$article->slug}/vote", ['type' => 'helpful']);
        $this->post("/help/{$article->slug}/vote", ['type' => 'helpful']);
        $this->post("/help/{$article->slug}/vote", ['type' => 'not_helpful'])->assertSessionHas('feedback_submitted');

        $article->refresh();
        $this->assertSame(1, $article->helpful_count);
        $this->assertSame(0, $article->not_helpful_count, 'A second vote of either kind is ignored');

        $this->flushSession();
        $this->post("/help/{$article->slug}/vote", ['type' => 'helpful']);

        $this->assertSame(2, $article->fresh()?->helpful_count, 'A different visitor can still vote');
    }

    public function test_customer_can_submit_ticket_from_public_portal(): void
    {
        $response = $this->get('/support');
        $response->assertSuccessful();
        $response->assertSee('Submit a Support Ticket');

        $postResponse = $this->post('/support', [
            'name' => 'Miles Dyson',
            'email' => 'miles.dyson@cyberdyne.test',
            'subject' => 'Cluster sync latency spike',
            'priority' => 'high',
            'description' => 'Observed 3000ms latency on deal sync operations.',
        ]);

        $postResponse->assertSessionHasNoErrors();

        /** @var Ticket $ticket */
        $ticket = Ticket::where('subject', 'Cluster sync latency spike')->first();
        $this->assertNotNull($ticket);
        $this->assertNotNull($ticket->portal_token);
        $this->assertSame(TicketPriority::High, $ticket->priority);

        // Auto-created Contact
        $this->assertNotNull($ticket->contact);
        $this->assertSame('miles.dyson@cyberdyne.test', $ticket->contact->email);
        $this->assertSame('Miles', $ticket->contact->first_name);
        $this->assertSame('Dyson', $ticket->contact->last_name);

        $postResponse->assertRedirect("/support/tickets/{$ticket->portal_token}");
    }

    public function test_customer_can_view_ticket_thread_and_post_reply(): void
    {
        $contact = Contact::factory()->create(['first_name' => 'Kyle', 'last_name' => 'Reese']);
        $ticket = Ticket::create([
            'subject' => 'Need updated API token',
            'description' => 'Current token expired.',
            'status' => TicketStatus::WaitingOnCustomer,
            'priority' => TicketPriority::Medium,
            'contact_id' => $contact->id,
        ]);

        $response = $this->get("/support/tickets/{$ticket->portal_token}");
        $response->assertSuccessful();
        $response->assertSee('Need updated API token');

        // Customer posts reply
        $replyResponse = $this->post("/support/tickets/{$ticket->portal_token}/reply", [
            'body' => 'I have regenerated the token in settings and confirmed it works now!',
        ]);

        $replyResponse->assertSessionHasNoErrors();
        $ticket->refresh();

        $this->assertSame(TicketStatus::WaitingOnAgent, $ticket->status);
        $this->assertCount(1, $ticket->messages);
        $this->assertSame('I have regenerated the token in settings and confirmed it works now!', $ticket->messages->first()?->body);
    }

    public function test_customer_can_rate_support_experience_csat(): void
    {
        $ticket = Ticket::create([
            'subject' => 'Billing inquiry',
            'status' => TicketStatus::Resolved,
            'priority' => TicketPriority::Low,
        ]);

        $response = $this->get("/support/rate/{$ticket->portal_token}");
        $response->assertSuccessful();
        $response->assertSee('How was our support?');

        $submitResponse = $this->post("/support/rate/{$ticket->portal_token}", [
            'rating' => 5,
            'comment' => 'Fast, courteous, and solved on the first touch.',
        ]);

        $submitResponse->assertSessionHasNoErrors();
        $ticket->refresh();

        $this->assertSame(5, $ticket->csat_rating);
        $this->assertSame('Fast, courteous, and solved on the first touch.', $ticket->csat_comment);
    }

    public function test_support_form_suggestion_script_builds_dom_nodes_instead_of_html(): void
    {
        $response = $this->get('/support');

        $response->assertSuccessful();
        $content = (string) $response->getContent();

        foreach (['title', 'category', 'excerpt', 'url', 'id'] as $field) {
            $this->assertStringNotContainsString('${item.'.$field.'}', $content);
        }
        $this->assertDoesNotMatchRegularExpression('/innerHTML\s*=\s*payload/', $content);
        $this->assertStringContainsString('link.href = safeUrl(item.url)', $content);
    }

    public function test_repeat_views_by_same_visitor_are_not_double_counted(): void
    {
        $article = KnowledgeArticle::create([
            'title' => 'Dedupe Views Guide',
            'slug' => 'dedupe-views',
            'category' => 'API',
            'body' => 'Body.',
            'is_published' => true,
            'views_count' => 0,
        ]);

        $this->get("/help/{$article->slug}")->assertSuccessful();
        $this->get("/help/{$article->slug}")->assertSuccessful();

        $this->assertSame(1, $article->fresh()->views_count);
    }

    public function test_invalid_vote_type_is_rejected_and_not_counted(): void
    {
        $article = KnowledgeArticle::create([
            'title' => 'Vote Validation Guide',
            'slug' => 'vote-validation',
            'category' => 'API',
            'body' => 'Body.',
            'is_published' => true,
            'helpful_count' => 0,
            'not_helpful_count' => 0,
        ]);

        $response = $this->post("/help/{$article->slug}/vote", ['type' => 'bogus']);

        $response->assertSessionHasErrors('type');

        $article->refresh();
        $this->assertSame(0, $article->helpful_count);
        $this->assertSame(0, $article->not_helpful_count);
    }
}
