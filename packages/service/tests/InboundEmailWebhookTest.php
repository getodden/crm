<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\SlaPolicy;
use Odden\Service\Models\Ticket;

class InboundEmailWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SlaPolicy::create(SlaPolicy::defaultPreset());
    }

    public function test_inbound_email_creates_new_ticket_and_provisions_contact(): void
    {
        $payload = [
            'from' => 'Jane Doe <jane.doe@enterprise.test>',
            'subject' => 'Cannot access reporting dashboard',
            'body' => 'Getting 403 Forbidden when clicking on Quarterly Reports.',
        ];

        $response = $this->postJson('/api/service/inbound-email', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure(['status', 'ticket_number', 'portal_url']);
        $this->assertSame('created', $response->json('status'));

        // Verify ticket
        $ticket = Ticket::where('ticket_number', $response->json('ticket_number'))->first();
        $this->assertNotNull($ticket);
        $this->assertSame('Cannot access reporting dashboard', $ticket->subject);
        $this->assertSame(TicketSource::Email, $ticket->source);
        $this->assertSame(TicketStatus::New, $ticket->status);

        // Verify Contact created
        $contact = Contact::where('email', 'jane.doe@enterprise.test')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('Doe', $contact->last_name);
        $this->assertSame($contact->id, $ticket->contact_id);

        // Verify initial message in thread
        $this->assertCount(1, $ticket->messages);
        $this->assertSame('Getting 403 Forbidden when clicking on Quarterly Reports.', $ticket->messages->first()?->body);
    }

    public function test_inbound_email_replying_to_a_ticket_message_id_appends_to_existing_conversation(): void
    {
        $contact = Contact::factory()->create([
            'email' => 'techlead@client.test',
            'first_name' => 'Alex',
            'last_name' => 'Smith',
        ]);

        $ticket = Ticket::create([
            'ticket_number' => 'TICK-2026-ABCD',
            'subject' => 'SAML Metadata expired',
            'status' => TicketStatus::WaitingOnCustomer,
            'contact_id' => $contact->id,
        ]);

        $payload = [
            'from' => 'Alex Smith <techlead@client.test>',
            'subject' => 'Re: [#TICK-2026-ABCD] SAML Metadata expired',
            'body' => 'Here is the renewed certificate attachment: SHA-256 cert renewed until 2028.',
            'In-Reply-To' => "<ticket.{$ticket->portal_token}.0a1b2c3d4e5f6a7b@crm.example.com>",
        ];

        $response = $this->postJson('/api/service/inbound-email', $payload);

        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'appended',
            'ticket_number' => 'TICK-2026-ABCD',
        ]);

        $ticket->refresh();
        $this->assertSame(TicketStatus::WaitingOnAgent, $ticket->status);
        $this->assertCount(1, $ticket->messages);
        $this->assertSame('Here is the renewed certificate attachment: SHA-256 cert renewed until 2028.', $ticket->messages->first()?->body);
    }

    public function test_inbound_email_requires_sender_and_body(): void
    {
        $response = $this->postJson('/api/service/inbound-email', [
            'subject' => 'Empty inquiry',
        ]);

        $response->assertStatus(422);
    }

    public function test_inbound_email_from_a_different_sender_does_not_thread_onto_the_referenced_ticket(): void
    {
        $customer = Contact::factory()->create(['email' => 'owner@client.test']);

        $ticket = Ticket::create([
            'ticket_number' => 'TICK-2026-OWNR1',
            'subject' => 'Billing question',
            'status' => TicketStatus::WaitingOnCustomer,
            'contact_id' => $customer->id,
        ]);

        $response = $this->postJson('/api/service/inbound-email', [
            'from' => 'Mallory <mallory@attacker.test>',
            'subject' => 'Re: [#TICK-2026-OWNR1] Billing question',
            'body' => "Please change the bank details. {$ticket->getPortalUrl()}",
        ]);

        $response->assertStatus(201);
        $this->assertSame('created', $response->json('status'));
        $this->assertNotSame('TICK-2026-OWNR1', $response->json('ticket_number'));

        $ticket->refresh();
        $this->assertSame(TicketStatus::WaitingOnCustomer, $ticket->status);
        $this->assertCount(0, $ticket->messages);

        $newTicket = Ticket::where('ticket_number', $response->json('ticket_number'))->first();
        $this->assertNotNull($newTicket);
        $this->assertSame('mallory@attacker.test', $newTicket->contact?->email);
    }

    public function test_inbound_email_sender_match_is_case_insensitive_and_trimmed(): void
    {
        $customer = Contact::factory()->create(['email' => 'Owner@Client.test']);

        $ticket = Ticket::create([
            'ticket_number' => 'TICK-2026-CASE1',
            'subject' => 'Export failing',
            'status' => TicketStatus::WaitingOnCustomer,
            'contact_id' => $customer->id,
        ]);

        $response = $this->postJson('/api/service/inbound-email', [
            'from' => 'Owner < owner@CLIENT.test >',
            'subject' => 'Re: [#TICK-2026-CASE1] Export failing',
            'body' => 'Still failing.',
            'References' => "<ticket.{$ticket->portal_token}.0a1b2c3d4e5f6a7b@crm.example.com>",
        ]);

        $response->assertOk()->assertJson(['status' => 'appended', 'ticket_number' => 'TICK-2026-CASE1']);
        $this->assertSame($customer->id, $ticket->messages()->sole()->contact_id);
        $this->assertSame(1, Contact::query()->count());
    }

    public function test_inbound_email_threads_by_support_portal_link_in_body(): void
    {
        $customer = Contact::factory()->create(['email' => 'portal@client.test']);

        $ticket = Ticket::create([
            'subject' => 'Webhook retries',
            'status' => TicketStatus::WaitingOnCustomer,
            'contact_id' => $customer->id,
        ]);

        $response = $this->postJson('/api/service/inbound-email', [
            'from' => 'portal@client.test',
            'subject' => 'Re: your request',
            'body' => "Thanks.\n\n> View your ticket: {$ticket->getPortalUrl()}",
        ]);

        $response->assertOk()->assertJson(['status' => 'appended', 'ticket_number' => $ticket->ticket_number]);
    }

    public function test_inbound_email_portal_link_from_a_different_sender_creates_a_new_ticket(): void
    {
        $customer = Contact::factory()->create(['email' => 'portal@client.test']);

        $ticket = Ticket::create([
            'subject' => 'Webhook retries',
            'status' => TicketStatus::WaitingOnCustomer,
            'contact_id' => $customer->id,
        ]);

        $response = $this->postJson('/api/service/inbound-email', [
            'from' => 'someone@else.test',
            'subject' => 'Re: your request',
            'body' => "> View your ticket: {$ticket->getPortalUrl()}",
        ]);

        $response->assertStatus(201)->assertJsonPath('status', 'created');
        $this->assertCount(0, $ticket->messages()->get());
    }

    public function test_inbound_email_does_not_thread_by_ticket_number_alone_whatever_the_prefix_or_case(): void
    {
        config(['odden-service.defaults.prefix' => 'Acme']);

        $customer = Contact::factory()->create(['email' => 'ops@client.test']);

        $ticket = Ticket::create([
            'subject' => 'Sync stalled',
            'status' => TicketStatus::WaitingOnCustomer,
            'contact_id' => $customer->id,
        ]);

        $this->assertStringStartsWith('Acme-', $ticket->ticket_number);

        foreach ([strtolower($ticket->ticket_number), $ticket->ticket_number] as $number) {
            $this->postJson('/api/service/inbound-email', [
                'from' => 'ops@client.test',
                'subject' => "Re: [{$number}] Sync stalled",
                'body' => "Any update on {$number}?",
                'In-Reply-To' => "<{$number}@mail.odden.test>",
            ])->assertStatus(201)->assertJsonPath('status', 'created');
        }

        $this->assertSame(0, $ticket->messages()->count());
    }
}
