<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Odden\Core\Models\Contact;
use Odden\Filament\Pages\ServiceCockpit;
use Odden\Filament\Tests\Fixtures\User;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\CannedResponse;
use Odden\Service\Models\KnowledgeArticle;
use Odden\Service\Models\Ticket;
use Odden\Service\Notifications\TicketRepliedNotification;
use Odden\Service\Notifications\TicketResolvedCsatNotification;

class ServiceCockpitTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_service_cockpit_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/admin/service-cockpit');

        $response->assertSuccessful();
        $response->assertSee('Support Agent Workspace');
        $response->assertSee('Unassigned Triage');
        $response->assertSee('My Active Queue');
        $response->assertSee('SLA Risk / Breached');
        $response->assertSee('Avg CSAT Rating');
    }

    public function test_support_agent_can_claim_unassigned_ticket(): void
    {
        $user = User::factory()->create();

        $contact = Contact::factory()->create([
            'first_name' => 'Gordon',
            'last_name' => 'Freeman',
            'email' => 'gordon@blackmesa.com',
        ]);

        $ticket = Ticket::create([
            'subject' => 'Anti-mass spectrometer calibration error',
            'description' => 'The resonance cascade has started.',
            'status' => TicketStatus::New,
            'priority' => TicketPriority::Urgent,
            'contact_id' => $contact->id,
            'owner_id' => null,
        ]);

        Livewire::actingAs($user)
            ->test(ServiceCockpit::class)
            ->call('claimTicket', $ticket->id)
            ->assertSuccessful();

        $ticket->refresh();
        $this->assertSame($user->id, $ticket->owner_id);
        $this->assertSame(TicketStatus::Open, $ticket->status);
    }

    public function test_support_agent_can_quick_reply_to_ticket(): void
    {
        $user = User::factory()->create();

        $ticket = Ticket::create([
            'subject' => 'Cannot configure SSO',
            'description' => 'SAML endpoint returns 500 error.',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::High,
            'owner_id' => $user->id,
        ]);

        $canned = CannedResponse::create([
            'title' => 'Need Logs',
            'shortcut' => '!logs',
            'category' => 'Troubleshooting',
            'content' => 'Could you please provide the latest SAML error log excerpt?',
            'is_shared' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ServiceCockpit::class)
            ->call('openReplyModal', $ticket->id)
            ->assertSet('showReplyModal', true)
            ->call('insertCannedResponse', $canned->id)
            ->assertSet('replyBody', $canned->content)
            ->set('replyStatus', 'waiting_on_customer')
            ->call('sendQuickReply')
            ->assertSuccessful()
            ->assertSet('showReplyModal', false);

        $ticket->refresh();
        $this->assertSame(TicketStatus::WaitingOnCustomer, $ticket->status);
        $this->assertCount(1, $ticket->messages);
        $this->assertStringContainsString('SAML error log excerpt', (string) $ticket->messages->first()?->body);
    }

    public function test_support_agent_can_quick_resolve_ticket(): void
    {
        $user = User::factory()->create();

        $ticket = Ticket::create([
            'subject' => 'Password reset link expired',
            'description' => 'User could not reset password within window.',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Medium,
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ServiceCockpit::class)
            ->call('openResolveModal', $ticket->id)
            ->assertSet('showResolveModal', true)
            ->set('resolveNote', 'Generated a fresh single-use reset token and validated login.')
            ->call('quickResolveTicket')
            ->assertSuccessful()
            ->assertSet('showResolveModal', false);

        $ticket->refresh();
        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertCount(1, $ticket->messages);
        $this->assertStringContainsString('fresh single-use reset token', (string) $ticket->messages->first()?->body);
    }

    public function test_support_agent_can_filter_tabs_and_search(): void
    {
        $user = User::factory()->create();

        $ticketA = Ticket::create([
            'subject' => 'Alpha billing issue',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Low,
            'owner_id' => $user->id,
        ]);

        $ticketB = Ticket::create([
            'subject' => 'Beta database timeout',
            'status' => TicketStatus::New,
            'priority' => TicketPriority::Urgent,
            'owner_id' => null,
        ]);

        $component = Livewire::actingAs($user)
            ->test(ServiceCockpit::class)
            ->call('setActiveTab', 'all')
            ->assertSet('activeTab', 'all');

        $this->assertEqualsCanonicalizing(
            [$ticketA->id, $ticketB->id],
            $component->instance()->allTickets->pluck('id')->all()
        );

        $component->set('search', 'Alpha');
        $this->assertSame([$ticketA->id], $component->instance()->allTickets->pluck('id')->all());

        $component->set('search', '')->call('setPriorityFilter', TicketPriority::Urgent->value)
            ->assertSet('priorityFilter', TicketPriority::Urgent->value);
        $this->assertSame([$ticketB->id], $component->instance()->allTickets->pluck('id')->all());
    }

    public function test_support_agent_receives_copilot_article_suggestions_and_can_insert_link(): void
    {
        $user = User::factory()->create();

        KnowledgeArticle::create([
            'title' => 'SSO Configuration Guide',
            'slug' => 'sso-configuration-guide',
            'category' => 'Security',
            'body' => 'Follow these instructions to configure Okta and Azure AD SAML 2.0 integration.',
            'is_published' => true,
            'helpful_count' => 12,
        ]);

        $ticket = Ticket::create([
            'subject' => 'Help with SSO configuration',
            'description' => 'We need SAML 2.0 instructions.',
            'status' => TicketStatus::Open,
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ServiceCockpit::class)
            ->call('openReplyModal', $ticket->id)
            ->assertSet('showReplyModal', true)
            ->call('insertArticleLink', 'SSO Configuration Guide', 'http://localhost/help/sso-configuration-guide')
            ->assertSee('SSO Configuration Guide')
            ->call('sendQuickReply')
            ->assertSuccessful();

        $ticket->refresh();
        $this->assertStringContainsString('SSO Configuration Guide', (string) $ticket->messages->first()?->body);
    }

    public function test_support_agent_can_filter_selected_user_and_insert_canned_response_with_string_or_int(): void
    {
        $user = User::factory()->create();

        $canned = CannedResponse::create([
            'title' => 'Greeting Template',
            'shortcut' => '!hello',
            'content' => 'Hello, thank you for reaching out to support!',
            'category' => 'General',
            'is_shared' => true,
        ]);

        $ticket = Ticket::create([
            'subject' => 'Need help',
            'description' => 'Help me please',
            'status' => TicketStatus::Open,
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ServiceCockpit::class)
            // Test string ID from select element
            ->call('setSelectedUser', (string) $user->id)
            ->assertSet('selectedUserId', $user->id)
            // Test empty string unassigned / all agents
            ->call('setSelectedUser', '')
            ->assertSet('selectedUserId', null)
            // Test inserting canned response with string ID
            ->call('openReplyModal', $ticket->id)
            ->call('insertCannedResponse', (string) $canned->id)
            ->assertSet('replyBody', 'Hello, thank you for reaching out to support!')
            ->call('insertCannedResponse', '')
            ->assertSuccessful();
    }

    public function test_quick_reply_emails_the_customer(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['email' => 'gordon@blackmesa.com']);
        $ticket = Ticket::create([
            'subject' => 'Cannot configure SSO',
            'description' => 'SAML endpoint returns 500 error.',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::High,
            'contact_id' => $contact->id,
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ServiceCockpit::class)
            ->call('openReplyModal', $ticket->id)
            ->set('replyBody', 'We are looking into it.')
            ->set('replyIsInternalNote', false)
            ->call('sendQuickReply');

        Notification::assertSentTo($contact, TicketRepliedNotification::class);
    }

    public function test_quick_resolve_emails_the_customer(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        $contact = Contact::factory()->create(['email' => 'gordon@blackmesa.com']);
        $ticket = Ticket::create([
            'subject' => 'Cannot configure SSO',
            'description' => 'SAML endpoint returns 500 error.',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::High,
            'contact_id' => $contact->id,
            'owner_id' => $user->id,
        ]);

        Livewire::actingAs($user)
            ->test(ServiceCockpit::class)
            ->call('openResolveModal', $ticket->id)
            ->set('resolveNote', 'Fixed the SAML endpoint.')
            ->call('quickResolveTicket');

        $this->assertSame(TicketStatus::Resolved, $ticket->refresh()->status);
        Notification::assertSentTo($contact, TicketResolvedCsatNotification::class);
    }

    public function test_inserting_a_canned_response_fills_in_the_ticket_variables(): void
    {
        $user = User::factory()->create(['name' => 'Dana Agent']);
        $contact = Contact::factory()->create(['first_name' => 'Gordon', 'email' => 'gordon@blackmesa.com']);
        $ticket = Ticket::create(['subject' => 'Cannot log in', 'contact_id' => $contact->id, 'status' => TicketStatus::Open, 'owner_id' => $user->id]);
        $canned = CannedResponse::create([
            'title' => 'Greeting',
            'shortcut' => '!hi',
            'category' => 'General',
            'content' => 'Hi {{contact.first_name}}, {{agent.name}} here about {{ticket.subject}}.',
            'is_shared' => true,
        ]);

        Livewire::actingAs($user)
            ->test(ServiceCockpit::class)
            ->call('openReplyModal', $ticket->id)
            ->call('insertCannedResponse', $canned->id)
            ->assertSet('replyBody', 'Hi Gordon, Dana Agent here about Cannot log in.');
    }
}
