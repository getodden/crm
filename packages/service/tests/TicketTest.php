<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Service\Actions\CreateTicketAction;
use Odden\Service\Actions\ReplyTicketAction;
use Odden\Service\Actions\ResolveTicketAction;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\SlaPolicy;
use Odden\Service\Models\Ticket;
use Odden\Service\Tests\Fixtures\User;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SlaPolicy::create(SlaPolicy::defaultPreset());
    }

    public function test_can_create_ticket_with_auto_generated_number_and_sla_deadlines(): void
    {
        $contact = Contact::factory()->create([
            'first_name' => 'Sarah',
            'last_name' => 'Connor',
        ]);

        $action = new CreateTicketAction;
        $ticket = $action->execute(
            subject: 'Unable to export compliance report',
            description: 'Receiving 500 error on CSV export.',
            priority: TicketPriority::High,
            source: TicketSource::WebPortal,
            contact: $contact
        );

        $this->assertGreaterThan(0, $ticket->id);
        $this->assertStringStartsWith('TICK-', $ticket->ticket_number);
        $this->assertSame(TicketStatus::New, $ticket->status);
        $this->assertSame(TicketPriority::High, $ticket->priority);
        $this->assertSame($contact->id, $ticket->contact_id);

        // High priority has 120m first response, 480m resolution in default policy
        $this->assertNotNull($ticket->first_response_due_at);
        $this->assertNotNull($ticket->resolution_due_at);
        $this->assertTrue($ticket->resolution_due_at->isAfter($ticket->first_response_due_at));

        // Initial customer message should be seeded
        $this->assertCount(1, $ticket->messages);
        $firstMessage = $ticket->messages->first();
        $this->assertNotNull($firstMessage);
        $this->assertSame('Receiving 500 error on CSV export.', $firstMessage->body);
        $this->assertSame(MessageSenderType::Customer, $firstMessage->sender_type);
    }

    public function test_contact_and_company_relationships_resolve_tickets(): void
    {
        $company = Company::factory()->create(['name' => 'Skynet Corp']);
        $contact = Contact::factory()->create(['first_name' => 'John', 'last_name' => 'Connor']);
        $contact->associateWith($company);

        $action = new CreateTicketAction;
        $ticket = $action->execute(
            subject: 'API Rate limit adjustment request',
            description: 'Need higher burst limit for batch sync.',
            priority: TicketPriority::Medium,
            contact: $contact
        );

        // Auto-associated company
        $this->assertSame($company->id, $ticket->company_id);

        // Test relationship on Contact
        $this->assertTrue($contact->tickets->contains($ticket));

        // Test relationship on Company
        $this->assertTrue($company->tickets->contains($ticket));
    }

    public function test_agent_reply_updates_ticket_status_and_records_first_responded_at(): void
    {
        $agent = User::factory()->create(['name' => 'Beth Caldwell']);
        $contact = Contact::factory()->create();

        $ticket = (new CreateTicketAction)->execute(
            subject: 'Billing question',
            description: 'Where is my latest invoice?',
            priority: TicketPriority::Low,
            contact: $contact
        );

        $this->assertNull($ticket->first_responded_at);

        // Agent replies
        $replyAction = new ReplyTicketAction;
        $message = $replyAction->execute(
            ticket: $ticket,
            body: 'Hello! You can view invoices in Settings -> Billing.',
            senderType: MessageSenderType::Agent,
            user: $agent,
            isInternalNote: false
        );

        $this->assertSame('Hello! You can view invoices in Settings -> Billing.', $message->body);
        $ticket->refresh();

        $this->assertNotNull($ticket->first_responded_at);
        $this->assertFalse($ticket->is_sla_response_breached);
        $this->assertSame(TicketStatus::WaitingOnCustomer, $ticket->status);
    }

    public function test_customer_reply_sets_ticket_status_to_waiting_on_agent(): void
    {
        $contact = Contact::factory()->create();
        $ticket = Ticket::create([
            'subject' => 'Issue with SSO',
            'status' => TicketStatus::WaitingOnCustomer,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::Email,
            'contact_id' => $contact->id,
        ]);

        $ticket->addMessage(
            body: 'I tried that and it still gives SAML error 400.',
            senderType: MessageSenderType::Customer,
            contactId: $contact->id
        );

        $ticket->refresh();
        $this->assertSame(TicketStatus::WaitingOnAgent, $ticket->status);
    }

    public function test_resolving_ticket_records_resolution_time_and_csat(): void
    {
        $ticket = Ticket::create([
            'subject' => 'Password reset required',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::Phone,
        ]);

        $resolveAction = new ResolveTicketAction;
        $resolveAction->execute(
            ticket: $ticket,
            resolutionNote: 'Reset credentials and verified user login.',
            csatRating: 5,
            csatComment: 'Agent was incredibly fast and helpful!'
        );

        $ticket->refresh();
        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);
        $this->assertFalse($ticket->is_sla_resolution_breached);
        $this->assertSame(5, $ticket->csat_rating);
        $this->assertSame('Agent was incredibly fast and helpful!', $ticket->csat_comment);
    }

    public function test_can_reopen_resolved_ticket(): void
    {
        $ticket = Ticket::create([
            'subject' => 'Feature clarification',
            'status' => TicketStatus::Resolved,
            'priority' => TicketPriority::Low,
            'source' => TicketSource::WebPortal,
            'resolved_at' => now()->subDay(),
        ]);

        $ticket->reopen();
        $ticket->refresh();

        $this->assertSame(TicketStatus::Open, $ticket->status);
        $this->assertNull($ticket->resolved_at);
    }

    public function test_priority_change_recalculates_sla_due_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-05 09:00:00'));

        $policy = SlaPolicy::create([
            'name' => 'Tiered SLA',
            'urgent_first_response_minutes' => 15,
            'urgent_resolution_minutes' => 60,
            'high_first_response_minutes' => 60,
            'high_resolution_minutes' => 240,
            'medium_first_response_minutes' => 120,
            'medium_resolution_minutes' => 480,
            'low_first_response_minutes' => 240,
            'low_resolution_minutes' => 960,
        ]);

        $ticket = Ticket::create([
            'subject' => 'Priority bump',
            'status' => TicketStatus::New,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::WebPortal,
            'sla_policy_id' => $policy->id,
        ]);

        $this->assertTrue($ticket->first_response_due_at->equalTo(Carbon::parse('2026-01-05 11:00:00')));

        $ticket->update(['priority' => TicketPriority::Urgent]);
        $ticket->refresh();

        $this->assertTrue($ticket->first_response_due_at->equalTo(Carbon::parse('2026-01-05 09:15:00')));
        $this->assertTrue($ticket->resolution_due_at->equalTo(Carbon::parse('2026-01-05 10:00:00')));

        Carbon::setTestNow();
    }

    public function test_inactive_default_sla_policy_is_not_applied_to_new_tickets(): void
    {
        SlaPolicy::query()->update(['is_active' => false]);

        $ticket = Ticket::create([
            'subject' => 'No active policy',
            'status' => TicketStatus::New,
            'priority' => TicketPriority::High,
            'source' => TicketSource::WebPortal,
        ]);

        $this->assertNull($ticket->sla_policy_id);
        $this->assertNull($ticket->first_response_due_at);
        $this->assertNull($ticket->resolution_due_at);
    }

    public function test_a_customer_message_always_leaves_the_ticket_waiting_on_agent(): void
    {
        foreach ([TicketStatus::New, TicketStatus::Open, TicketStatus::WaitingOnCustomer, TicketStatus::WaitingOnAgent] as $status) {
            $ticket = Ticket::create(['subject' => "From {$status->value}", 'status' => $status]);

            $ticket->addMessage('Any news?', MessageSenderType::Customer);

            $this->assertSame(TicketStatus::WaitingOnAgent, $ticket->fresh()->status, "Customer message on a {$status->value} ticket");
        }
    }

    public function test_an_internal_note_or_agent_reply_does_not_make_the_ticket_wait_on_the_agent(): void
    {
        $ticket = Ticket::create(['subject' => 'Agent side', 'status' => TicketStatus::Open]);

        $ticket->addMessage('Internal thought', MessageSenderType::Agent, isInternalNote: true);

        $this->assertSame(TicketStatus::Open, $ticket->fresh()->status);
    }
}
