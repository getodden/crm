<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Odden\Core\Models\Contact;
use Odden\Service\Actions\MergeTicketsAction;
use Odden\Service\Actions\ReplyTicketAction;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\Ticket;
use Odden\Service\Models\TicketMessage;
use Odden\Service\Notifications\Concerns\SetsTicketMessageId;

/**
 * Issue #16: customer replies reopen resolved/closed tickets, and replies to a merged ticket
 * land on its primary ticket.
 */
class CustomerReplyRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_inbound_reply_reopens_a_closed_ticket(): void
    {
        [, $ticket] = $this->ticketFor('dana@client.test');
        $ticket->close();

        $this->replyByEmail('dana@client.test', $ticket)->assertOk()->assertJsonPath('status', 'appended');

        $ticket->refresh();
        $this->assertSame(TicketStatus::WaitingOnAgent, $ticket->status);
        $this->assertNull($ticket->closed_at);
        $this->assertNull($ticket->resolved_at);
        $this->assertSame(1, $ticket->messages()->where('body', 'Still broken.')->count());
    }

    public function test_inbound_reply_reopens_a_resolved_ticket(): void
    {
        [, $ticket] = $this->ticketFor('dana@client.test');
        $ticket->resolve();

        $this->replyByEmail('dana@client.test', $ticket)->assertOk();

        $ticket->refresh();
        $this->assertSame(TicketStatus::WaitingOnAgent, $ticket->status);
        $this->assertNull($ticket->resolved_at);
    }

    public function test_portal_reply_reopens_a_closed_ticket(): void
    {
        [, $ticket] = $this->ticketFor('dana@client.test');
        $ticket->close();

        $this->post("/support/tickets/{$ticket->portal_token}/reply", ['body' => 'Back again.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(TicketStatus::WaitingOnAgent, $ticket->refresh()->status);
    }

    public function test_reopening_can_be_turned_off(): void
    {
        config(['odden-service.reopen_on_customer_reply' => false]);

        [, $closed] = $this->ticketFor('dana@client.test');
        $closed->close();
        [, $resolved] = $this->ticketFor('dana@client.test');
        $resolved->resolve();

        $this->replyByEmail('dana@client.test', $closed)->assertOk();
        $this->replyByEmail('dana@client.test', $resolved)->assertOk();

        $this->assertSame(TicketStatus::Closed, $closed->refresh()->status);
        $this->assertSame(1, $closed->messages()->where('body', 'Still broken.')->count());
        $this->assertSame(TicketStatus::Resolved, $resolved->refresh()->status);
        $this->assertNotNull($resolved->resolved_at);
    }

    public function test_agent_reply_does_not_reopen_a_closed_ticket(): void
    {
        [, $ticket] = $this->ticketFor('dana@client.test');
        $ticket->close();

        (new ReplyTicketAction)->execute($ticket, 'Following up.', MessageSenderType::Agent);

        $this->assertSame(TicketStatus::Closed, $ticket->refresh()->status);
    }

    public function test_inbound_reply_to_a_merged_ticket_goes_to_the_primary(): void
    {
        [$contact, $primary] = $this->ticketFor('dana@client.test');
        [, $secondary] = $this->ticketFor('dana@client.test');
        (new MergeTicketsAction)->execute($primary, $secondary);

        $this->replyByEmail('dana@client.test', $secondary)
            ->assertOk()
            ->assertJson(['status' => 'appended', 'ticket_number' => $primary->ticket_number]);

        $message = $primary->messages()->where('body', 'Still broken.')->sole();
        $this->assertSame($contact->id, $message->contact_id);
        $this->assertSame(0, $secondary->messages()->where('body', 'Still broken.')->count());
        $this->assertSame(TicketStatus::Closed, $secondary->refresh()->status);
    }

    public function test_reply_follows_the_whole_merge_chain(): void
    {
        [, $first] = $this->ticketFor('dana@client.test');
        [, $middle] = $this->ticketFor('dana@client.test');
        [, $last] = $this->ticketFor('dana@client.test');
        (new MergeTicketsAction)->execute($middle, $first);
        (new MergeTicketsAction)->execute($last, $middle);

        $this->replyByEmail('dana@client.test', $first)->assertJsonPath('ticket_number', $last->ticket_number);

        $this->assertSame(1, $last->messages()->where('body', 'Still broken.')->count());
    }

    public function test_reply_to_a_merged_ticket_reopens_a_closed_primary(): void
    {
        [, $primary] = $this->ticketFor('dana@client.test');
        [, $secondary] = $this->ticketFor('dana@client.test');
        (new MergeTicketsAction)->execute($primary, $secondary);
        $primary->close();

        $this->replyByEmail('dana@client.test', $secondary)->assertOk();

        $this->assertSame(TicketStatus::WaitingOnAgent, $primary->refresh()->status);
        $this->assertSame(TicketStatus::Closed, $secondary->refresh()->status);
    }

    public function test_sender_is_checked_against_the_referenced_ticket_not_the_primary(): void
    {
        // The primary belongs to someone else: the merged ticket's customer can still reply,
        // and the primary's customer cannot use the merged ticket's token.
        [, $primary] = $this->ticketFor('owner@client.test');
        [, $secondary] = $this->ticketFor('dana@client.test');
        (new MergeTicketsAction)->execute($primary, $secondary);

        $this->replyByEmail('dana@client.test', $secondary)->assertJsonPath('ticket_number', $primary->ticket_number);

        $this->replyByEmail('owner@client.test', $secondary)->assertCreated()->assertJsonPath('status', 'created');
        $this->assertSame(1, $primary->messages()->where('body', 'Still broken.')->count());
    }

    public function test_portal_reply_to_a_merged_emailed_ticket_goes_to_the_primary(): void
    {
        // The token of a ticket created from an email or by an agent only ever reached the
        // customer's mailbox, so replying with it after a merge follows the merge.
        [, $primary] = $this->ticketFor('dana@client.test');
        [, $secondary] = $this->ticketFor('dana@client.test', TicketSource::Email);
        (new MergeTicketsAction)->execute($primary, $secondary);

        $this->post("/support/tickets/{$secondary->portal_token}/reply", ['body' => 'Portal follow-up.'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(1, $primary->messages()->where('body', 'Portal follow-up.')->count());
        $this->assertSame(0, $secondary->messages()->where('body', 'Portal follow-up.')->count());
    }

    public function test_portal_reply_to_a_merged_self_service_ticket_is_refused(): void
    {
        // Portal and chat tickets hand their token to whoever filled in the form, without
        // verifying the email address, so the token never reaches the primary ticket.
        foreach ([TicketSource::WebPortal, TicketSource::Chat] as $source) {
            [, $primary] = $this->ticketFor('dana@client.test');
            [, $secondary] = $this->ticketFor('dana@client.test', $source);
            (new MergeTicketsAction)->execute($primary, $secondary);

            $this->post("/support/tickets/{$secondary->portal_token}/reply", ['body' => 'Portal follow-up.'])
                ->assertSessionHasErrors('body');

            $this->assertSame(0, $primary->messages()->where('body', 'Portal follow-up.')->count());
            $this->assertSame(0, $secondary->messages()->where('body', 'Portal follow-up.')->count());
            $this->assertSame(TicketStatus::Closed, $secondary->refresh()->status);
        }
    }

    public function test_chat_message_to_a_merged_ticket_is_refused(): void
    {
        [, $primary] = $this->ticketFor('dana@client.test');
        [, $secondary] = $this->ticketFor('dana@client.test', TicketSource::Chat);
        (new MergeTicketsAction)->execute($primary, $secondary);

        $this->postJson(route('odden.service.chat.message', ['token' => $secondary->portal_token]), ['message' => 'Chat follow-up.'])
            ->assertStatus(409)
            ->assertJsonPath('merged', true)
            ->assertJsonPath('success', false);

        $this->assertSame(0, $primary->messages()->where('body', 'Chat follow-up.')->count());
        $this->assertSame(0, $secondary->messages()->where('body', 'Chat follow-up.')->count());
    }

    public function test_chat_started_with_someone_elses_email_cannot_read_or_write_their_ticket_after_a_merge(): void
    {
        // Attack: start a chat as the victim, get the chat ticket merged into the victim's real
        // ticket, then use the chat token on the chat API and on the portal.
        [, $victimTicket] = $this->ticketFor('victim@corp.test', TicketSource::Email);
        $victimTicket->addMessage('My account number is 12345.', MessageSenderType::Customer);
        $victimTicket->addMessage('Here is your reset code: 987654.', MessageSenderType::Agent);

        $token = (string) $this->postJson(route('odden.service.chat.start'), [
            'name' => 'Not The Victim',
            'email' => 'victim@corp.test',
            'message' => 'Same problem as my other ticket.',
        ])->assertCreated()->json('token');

        $chatTicket = Ticket::query()->where('portal_token', $token)->sole();
        $this->assertSame($victimTicket->contact_id, $chatTicket->contact_id);
        (new MergeTicketsAction)->execute($victimTicket, $chatTicket);

        $read = $this->getJson(route('odden.service.chat.messages', ['token' => $token]))
            ->assertOk()
            ->assertJsonPath('merged', true)
            ->assertJsonPath('ticket_number', $chatTicket->ticket_number);
        $this->assertStringContainsString('check your email', (string) $read->json('notice'));
        $this->assertStringNotContainsString('12345', (string) $read->getContent());
        $this->assertStringNotContainsString('987654', (string) $read->getContent());
        $this->assertStringNotContainsString('Same problem', (string) $read->getContent());
        $this->assertStringNotContainsString($victimTicket->ticket_number, (string) $read->getContent());

        $write = $this->postJson(route('odden.service.chat.message', ['token' => $token]), ['message' => 'Injected via chat.'])
            ->assertStatus(409);
        $this->assertStringNotContainsString('987654', (string) $write->getContent());

        $page = $this->get("/support/tickets/{$token}")->assertOk();
        $page->assertDontSee('12345');
        $page->assertDontSee('987654');
        $page->assertDontSee($victimTicket->ticket_number);

        $this->post("/support/tickets/{$token}/reply", ['body' => 'Injected via portal.'])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, TicketMessage::query()->where('body', 'like', 'Injected%')->count());
    }

    public function test_portal_ticket_submitted_with_someone_elses_email_cannot_post_into_their_ticket_after_a_merge(): void
    {
        [, $victimTicket] = $this->ticketFor('victim@corp.test', TicketSource::Email);
        $victimTicket->close();

        $this->post('/support', [
            'name' => 'Not The Victim',
            'email' => 'victim@corp.test',
            'subject' => 'Cannot log in',
            'priority' => 'low',
            'description' => 'Same problem as my other ticket.',
        ])->assertRedirect();

        $portalTicket = Ticket::query()->where('source', TicketSource::WebPortal)->latest('id')->firstOrFail();
        (new MergeTicketsAction)->execute($victimTicket, $portalTicket);

        $this->get("/support/tickets/{$portalTicket->portal_token}")->assertOk()->assertDontSee('Same problem');

        $this->post("/support/tickets/{$portalTicket->portal_token}/reply", ['body' => 'Injected via portal.'])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, TicketMessage::query()->where('body', 'Injected via portal.')->count());
        $this->assertSame(TicketStatus::Closed, $victimTicket->refresh()->status);
    }

    public function test_chat_message_reopens_a_closed_ticket(): void
    {
        [, $ticket] = $this->ticketFor('dana@client.test');
        $ticket->close();

        $this->postJson(route('odden.service.chat.message', ['token' => $ticket->portal_token]), ['message' => 'Hello again.'])
            ->assertOk();

        $this->assertSame(TicketStatus::WaitingOnAgent, $ticket->refresh()->status);
    }

    /**
     * @return array{0: Contact, 1: Ticket}
     */
    private function ticketFor(string $email, TicketSource $source = TicketSource::WebPortal): array
    {
        $contact = Contact::query()->where('email', $email)->first() ?? Contact::factory()->create(['email' => $email]);
        $ticket = Ticket::create(['subject' => 'Cannot log in', 'contact_id' => $contact->id, 'status' => TicketStatus::Open, 'source' => $source]);

        return [$contact, $ticket];
    }

    private function replyByEmail(string $from, Ticket $ticket): TestResponse
    {
        return $this->postJson('/api/service/inbound-email', [
            'from' => $from,
            'subject' => 'Re: Cannot log in',
            'body' => 'Still broken.',
            'in_reply_to' => '<'.SetsTicketMessageId::ticketMessageId($ticket).'>',
        ]);
    }
}
