<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\Ticket;

class ChatWidgetTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_start_live_chat_session_creating_contact_and_ticket(): void
    {
        $payload = [
            'name' => 'Sarah Connor',
            'email' => 'sarah@cyberdyne.test',
            'company' => 'Cyberdyne Systems',
            'message' => 'Hello! We need assistance setting up webhook triggers.',
        ];

        $response = $this->postJson(route('odden.service.chat.start'), $payload);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'token',
                'ticket_number',
                'messages',
            ]);

        $token = (string) $response->json('token');
        $this->assertNotEmpty($token);

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->where('portal_token', $token)->first();
        $this->assertNotNull($ticket);
        $this->assertSame(TicketSource::Chat, $ticket->source);
        // Created by CreateTicketAction like other channels: New until a routing rule assigns it.
        $this->assertSame(TicketStatus::New, $ticket->status);

        /** @var Contact|null $contact */
        $contact = Contact::query()->where('email', 'sarah@cyberdyne.test')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Sarah', $contact->first_name);
        $this->assertSame('Connor', $contact->last_name);
        $this->assertSame($contact->id, $ticket->contact_id);

        /** @var Company|null $company */
        $company = Company::query()->where('name', 'Cyberdyne Systems')->first();
        $this->assertNotNull($company);
        $this->assertSame($company->id, $ticket->company_id);

        // Verify initial customer message & system greeting
        $messages = $ticket->messages;
        $this->assertCount(2, $messages);
        $this->assertStringContainsString('Hello! We need assistance setting up webhook triggers.', $messages[0]->body);
        $this->assertStringContainsString('Thanks for reaching out', $messages[1]->body);
    }

    public function test_can_send_subsequent_chat_message(): void
    {
        $startResponse = $this->postJson(route('odden.service.chat.start'), [
            'name' => 'Kyle Reese',
            'email' => 'kyle@future.test',
            'message' => 'First message.',
        ]);

        $token = (string) $startResponse->json('token');

        $messageResponse = $this->postJson(route('odden.service.chat.message', ['token' => $token]), [
            'message' => 'Follow up message with more details.',
        ]);

        $messageResponse->assertOk()
            ->assertJsonPath('success', true);

        /** @var Ticket|null $ticket */
        $ticket = Ticket::query()->where('portal_token', $token)->first();
        $this->assertNotNull($ticket);
        $this->assertSame(3, $ticket->messages()->count());
    }

    public function test_can_fetch_chat_messages_by_token(): void
    {
        $startResponse = $this->postJson(route('odden.service.chat.start'), [
            'name' => 'Miles Dyson',
            'email' => 'miles@future.test',
            'message' => 'Can we upgrade our tier?',
        ]);

        $token = (string) $startResponse->json('token');

        $fetchResponse = $this->getJson(route('odden.service.chat.messages', ['token' => $token]));

        $fetchResponse->assertOk()
            ->assertJsonStructure([
                'ticket_number',
                'status',
                'messages' => [
                    '*' => ['id', 'sender_type', 'sender_name', 'body', 'is_customer', 'created_at'],
                ],
            ]);
    }

    public function test_widget_script_renders_message_fields_as_text_not_html(): void
    {
        $script = (string) file_get_contents(__DIR__.'/../resources/js/widget.js');

        // Message fields (sender_name, body, created_at) must never be interpolated into an HTML string.
        $this->assertDoesNotMatchRegularExpression('/\$\{\s*m\.\w+/', $script);
        $this->assertStringContainsString('sender.textContent = m.sender_name', $script);
        $this->assertStringContainsString('body.textContent = m.body', $script);
        $this->assertStringContainsString('encodeURIComponent(currentToken)', $script);
    }

    public function test_widget_script_is_served_by_a_route(): void
    {
        $response = $this->get(route('odden.service.widget'));

        $response->assertSuccessful();
        $this->assertStringContainsString('javascript', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('OddenChatWidgetLoaded', $response->streamedContent());
    }

    public function test_starting_a_chat_with_an_existing_address_reveals_and_changes_nothing_about_that_contact(): void
    {
        $victim = Contact::create(['first_name' => 'Victoria', 'last_name' => 'Hale', 'email' => 'victoria@example.com']);

        $response = $this->postJson(route('odden.service.chat.start'), [
            'name' => 'Mallory Fox',
            'email' => 'Victoria@Example.com',
            'message' => 'Hello?',
            'company' => 'Mallory Holdings',
        ]);

        $response->assertStatus(201);
        $body = (string) $response->getContent();
        $this->assertStringNotContainsString('Victoria', $body);
        $this->assertStringNotContainsString('Hale', $body);
        $this->assertStringContainsString('Hi Mallory!', $body, 'The greeting uses what the visitor typed');

        $names = collect($response->json('messages'))->where('is_customer', true)->pluck('sender_name')->unique()->all();
        $this->assertSame(['You'], $names);

        $ticket = Ticket::query()->where('ticket_number', $response->json('ticket_number'))->firstOrFail();
        $this->assertSame('Live Chat inquiry from Mallory Fox', $ticket->subject);
        $this->assertCount(0, $victim->fresh()->companies, 'Their company was not changed from the chat');
        $this->assertNull(Company::query()->where('name', 'Mallory Holdings')->first());

        // The ticket page the visitor can open does not show the stored name either.
        $this->get(route('odden.support.show', ['token' => $ticket->portal_token]))->assertOk()->assertDontSee('Victoria')->assertSee('You');
    }

    public function test_a_new_visitor_can_still_give_a_company(): void
    {
        $this->postJson(route('odden.service.chat.start'), [
            'name' => 'Nia Park',
            'email' => 'nia@example.com',
            'message' => 'Hi',
            'company' => 'Nia Ltd',
        ])->assertStatus(201);

        $nia = Contact::query()->where('email', 'nia@example.com')->firstOrFail();
        $this->assertCount(1, $nia->companies);
    }
}
