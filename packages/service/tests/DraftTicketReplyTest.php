<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Contact;
use Odden\Service\Actions\DraftTicketReplyAction;
use Odden\Service\Contracts\DraftsTicketReply;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\CannedResponse;
use Odden\Service\Models\KnowledgeArticle;
use Odden\Service\Models\Ticket;
use Odden\Service\Tests\Fixtures\User;

class DraftTicketReplyTest extends TestCase
{
    use RefreshDatabase;

    private function ticket(string $subject, ?Contact $contact = null): Ticket
    {
        return Ticket::create([
            'ticket_number' => 'TICK-2026-'.strtoupper(substr(md5($subject), 0, 5)),
            'subject' => $subject,
            'status' => TicketStatus::New,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::Api,
            'contact_id' => $contact?->id,
        ]);
    }

    public function test_the_built_in_drafter_is_bound_by_default(): void
    {
        $this->assertInstanceOf(DraftTicketReplyAction::class, app(DraftsTicketReply::class));
    }

    public function test_an_application_can_replace_the_drafter(): void
    {
        $this->app->bind(DraftsTicketReply::class, fn () => new class implements DraftsTicketReply
        {
            public function execute(Ticket $ticket, ?Model $agent = null): array
            {
                return ['body' => "Custom: {$ticket->subject}", 'sources' => [], 'rationale' => 'custom'];
            }
        });

        $this->assertSame('Custom: Login problem', app(DraftsTicketReply::class)->execute($this->ticket('Login problem'))['body']);
    }

    public function test_a_ticket_with_nothing_to_match_gets_a_general_acknowledgement_in_the_agents_name(): void
    {
        $agent = User::factory()->create(['name' => 'Beth Caldwell']);
        $contact = Contact::factory()->create(['first_name' => 'Sarah']);

        $draft = app(DraftsTicketReply::class)->execute($this->ticket('Strange glitch', $contact), $agent);

        $this->assertSame(['body', 'sources', 'rationale'], array_keys($draft));
        $this->assertStringStartsWith('Hi Sarah,', $draft['body']);
        $this->assertStringContainsString('"Strange glitch"', $draft['body']);
        $this->assertStringEndsWith("Best regards,\nBeth Caldwell", $draft['body']);
        $this->assertSame([], $draft['sources']);
    }

    public function test_the_best_matching_canned_response_is_filled_in_for_the_ticket(): void
    {
        $agent = User::factory()->create();
        $contact = Contact::factory()->create(['first_name' => 'Sarah']);
        CannedResponse::create(['title' => 'Password reset', 'shortcut' => '!pw', 'category' => 'Account', 'content' => 'Use the Forgot password link, {{contact.first_name}}.', 'is_shared' => true]);
        CannedResponse::create(['title' => 'Refund policy', 'shortcut' => '!refund', 'category' => 'Billing', 'content' => 'Refunds take five days.', 'is_shared' => true]);

        $draft = app(DraftsTicketReply::class)->execute($this->ticket('Password reset not working', $contact), $agent);

        $this->assertStringContainsString('Use the Forgot password link, Sarah.', $draft['body']);
        $this->assertStringNotContainsString('Refunds', $draft['body']);
        $this->assertContains('Canned response: Password reset', $draft['sources']);
    }

    public function test_canned_responses_the_agent_may_not_use_are_not_drafted_from(): void
    {
        $agent = User::factory()->create();
        $other = User::factory()->create();
        CannedResponse::create(['title' => 'Password reset', 'shortcut' => '!pw', 'category' => 'Account', 'content' => 'PRIVATE TEXT', 'user_id' => $other->id, 'is_shared' => false]);

        $draft = app(DraftsTicketReply::class)->execute($this->ticket('Password reset not working'), $agent);

        $this->assertStringNotContainsString('PRIVATE TEXT', $draft['body']);
    }

    public function test_matching_published_articles_are_linked_and_unpublished_ones_are_not(): void
    {
        KnowledgeArticle::create(['title' => 'Resetting your password', 'slug' => 'resetting-password', 'category' => 'Account', 'body' => 'Click Forgot password.', 'is_published' => true]);
        KnowledgeArticle::create(['title' => 'Internal password runbook', 'slug' => 'internal-password', 'category' => 'Internal', 'body' => 'Rotate keys.', 'is_published' => false]);

        $draft = app(DraftsTicketReply::class)->execute($this->ticket('Forgot my password'));

        $this->assertStringContainsString('Resetting your password', $draft['body']);
        $this->assertStringContainsString('resetting-password', $draft['body']);
        $this->assertStringNotContainsString('Internal password runbook', $draft['body']);
    }

    public function test_the_customers_latest_message_counts_but_internal_notes_do_not(): void
    {
        $agent = User::factory()->create();
        CannedResponse::create(['title' => 'Invoice copy', 'shortcut' => '!inv', 'category' => 'Billing', 'content' => 'We will resend the invoice.', 'is_shared' => true]);
        $ticket = $this->ticket('Question');
        $ticket->addMessage('Please resend my invoice', MessageSenderType::Customer);
        $ticket->addMessage('invoice copy invoice copy', MessageSenderType::Customer, isInternalNote: true);

        $draft = app(DraftsTicketReply::class)->execute($ticket, $agent);

        $this->assertStringContainsString('We will resend the invoice.', $draft['body']);
    }
}
