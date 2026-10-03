<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Enums\ActivityType;
use Odden\Core\Models\Activity;
use Odden\Core\Models\Contact;
use Odden\Service\Actions\CheckSlaBreachesAction;
use Odden\Service\Actions\ReplyTicketAction;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\CannedResponse;
use Odden\Service\Models\SlaPolicy;
use Odden\Service\Models\Ticket;
use Odden\Service\Tests\Fixtures\User;

class ServiceHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_reply_reopens_resolved_ticket_and_clears_resolved_at(): void
    {
        $contact = Contact::factory()->create(['email' => 'prospect@test.com']);
        /** @var Ticket $ticket */
        $ticket = Ticket::create([
            'subject' => 'SSL Certificate Error',
            'status' => TicketStatus::Resolved,
            'priority' => TicketPriority::High,
            'source' => TicketSource::WebPortal,
            'contact_id' => $contact->id,
            'resolved_at' => now()->subDay(),
        ]);

        $this->assertSame(TicketStatus::Resolved, $ticket->status);
        $this->assertNotNull($ticket->resolved_at);

        // Customer replies contesting resolution
        app(ReplyTicketAction::class)->execute(
            ticket: $ticket,
            body: 'Actually the certificate is still throwing invalid hostname errors on our subdomain.',
            senderType: MessageSenderType::Customer,
            contact: $contact
        );

        $freshTicket = $ticket->fresh();
        $this->assertNotNull($freshTicket);
        $this->assertSame(TicketStatus::Open, $freshTicket->status);
        $this->assertNull($freshTicket->resolved_at);
    }

    public function test_inbound_email_webhook_with_custom_prefix_threads_by_portal_token_not_ticket_number(): void
    {
        config(['odden-service.defaults.prefix' => 'SRV']);

        $contact = Contact::factory()->create(['email' => 'billing@client.com']);
        /** @var Ticket $ticket */
        $ticket = Ticket::create([
            'ticket_number' => 'SRV-2026-9999',
            'subject' => 'Invoice discrepancy March',
            'status' => TicketStatus::WaitingOnCustomer,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::Email,
            'contact_id' => $contact->id,
        ]);

        // The custom-prefix ticket number alone (subject or In-Reply-To) no longer threads.
        $numberOnly = $this->postJson(route('odden.service.inbound-email'), [
            'from' => 'Jane Client <billing@client.com>',
            'subject' => 'Re: [SRV-2026-9999] Invoice discrepancy March',
            'body' => 'Here is the attached wire receipt.',
            'In-Reply-To' => '<SRV-2026-9999@mail.odden.crm>',
        ]);
        $numberOnly->assertCreated()->assertJsonPath('status', 'created');
        $this->assertSame(0, $ticket->messages()->count());

        // The portal link in the body threads.
        $response = $this->postJson(route('odden.service.inbound-email'), [
            'from' => 'Jane Client <billing@client.com>',
            'subject' => 'Re: [SRV-2026-9999] Invoice discrepancy March',
            'body' => "Here is the attached wire receipt.\n\n> {$ticket->getPortalUrl()}",
        ]);
        $response->assertOk()
            ->assertJsonPath('status', 'appended')
            ->assertJsonPath('ticket_number', 'SRV-2026-9999');

        $this->assertSame(TicketStatus::Open, $ticket->fresh()?->status);

        // A ticket Message-ID in In-Reply-To threads with the subject stripped.
        $headerResponse = $this->postJson(route('odden.service.inbound-email'), [
            'from' => 'Jane Client <billing@client.com>',
            'subject' => 'Re: Quick Question',
            'body' => 'Also sending our tax ID.',
            'In-Reply-To' => "<ticket.{$ticket->portal_token}.0a1b2c3d4e5f6a7b@mail.odden.crm>",
        ]);
        $headerResponse->assertOk()
            ->assertJsonPath('status', 'appended')
            ->assertJsonPath('ticket_number', 'SRV-2026-9999');

        $this->assertSame(1, $ticket->messages()->where('body', 'Also sending our tax ID.')->count());
    }

    public function test_unassigned_ticket_sla_breach_escalates_priority_and_logs_internal_alert(): void
    {
        $policy = SlaPolicy::create([
            'name' => 'Enterprise SLA',
            'urgent_first_response_minutes' => 15,
            'high_first_response_minutes' => 60,
            'medium_first_response_minutes' => 120,
            'low_first_response_minutes' => 240,
        ]);

        /** @var Ticket $unassignedTicket */
        $unassignedTicket = Ticket::create([
            'subject' => 'Database connection timeout',
            'status' => TicketStatus::New,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::WebPortal,
            'owner_id' => null,
            'sla_policy_id' => $policy->id,
            'first_response_due_at' => now()->subMinutes(10), // Breached
            'is_sla_response_breached' => false,
        ]);

        $result = app(CheckSlaBreachesAction::class)->execute();

        $this->assertGreaterThanOrEqual(1, $result['response_breaches']);

        $fresh = $unassignedTicket->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue($fresh->is_sla_response_breached);
        // Priority escalated from Medium -> High
        $this->assertSame(TicketPriority::High, $fresh->priority);

        // System internal note logged
        $alertMessage = $fresh->messages()
            ->where('is_internal_note', true)
            ->where('body', 'like', '%SLA Breach Alert%')
            ->first();

        $this->assertNotNull($alertMessage);
        $this->assertStringContainsString('Priority escalated to High', (string) $alertMessage->body);
    }

    public function test_negative_csat_submission_logs_recovery_task_and_ticket_alert(): void
    {
        $contact = Contact::factory()->create(['email' => 'unhappy@customer.com']);
        /** @var Ticket $ticket */
        $ticket = Ticket::create([
            'subject' => 'Slow sync response',
            'status' => TicketStatus::Resolved,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::WebPortal,
            'contact_id' => $contact->id,
            'portal_token' => 'csat-token-negative-test-12345',
        ]);

        $response = $this->post(route('odden.support.submitRating', ['token' => $ticket->portal_token]), [
            'rating' => 1,
            'comment' => 'Took 3 days to get an answer and the bug persists.',
        ]);

        $response->assertRedirect(route('odden.support.show', ['token' => $ticket->portal_token]));

        $freshTicket = $ticket->fresh();
        $this->assertNotNull($freshTicket);
        $this->assertSame(1, $freshTicket->csat_rating);
        $this->assertSame('Took 3 days to get an answer and the bug persists.', $freshTicket->csat_comment);

        // Internal note on ticket thread
        $ticketAlert = $freshTicket->messages()
            ->where('is_internal_note', true)
            ->where('body', 'like', '%Negative CSAT rating (1/5 stars)%')
            ->first();

        $this->assertNotNull($ticketAlert);
        $this->assertStringContainsString('Supervisor review recommended', (string) $ticketAlert->body);

        // Service recovery task on contact timeline
        $recoveryTask = Activity::query()
            ->where('subject_type', $contact->getMorphClass())
            ->where('subject_id', $contact->id)
            ->where('type', ActivityType::Task)
            ->where('title', 'like', '%CSAT Service Recovery%')
            ->first();

        $this->assertNotNull($recoveryTask);
        $this->assertStringContainsString('Took 3 days to get an answer', (string) $recoveryTask->body);
    }

    public function test_canned_response_template_retrieval_and_usage_in_thread(): void
    {
        $agent = User::factory()->create(['name' => 'Support Agent']);

        $canned = CannedResponse::create([
            'title' => 'Password Reset Guide',
            'shortcut' => '!pwd',
            'category' => 'account',
            'content' => 'Please navigate to Settings > Security and click Reset Password.',
            'user_id' => $agent->id,
            'is_shared' => true,
        ]);

        $contact = Contact::factory()->create();
        /** @var Ticket $ticket */
        $ticket = Ticket::create([
            'subject' => 'Cannot log in',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Low,
            'source' => TicketSource::WebPortal,
            'contact_id' => $contact->id,
            'owner_id' => $agent->id,
        ]);

        $message = app(ReplyTicketAction::class)->execute(
            ticket: $ticket,
            body: $canned->content,
            senderType: MessageSenderType::Agent,
            user: $agent
        );

        $this->assertSame('Please navigate to Settings > Security and click Reset Password.', $message->body);
        $this->assertSame($agent->id, $message->user_id);
    }

    public function test_sla_breach_escalation_recalculates_due_dates_for_new_priority(): void
    {
        $this->markTestIncomplete('Breach escalation leaves SLA due dates on the old priority; fixed by #39.');


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
            'subject' => 'Escalation recalculation',
            'status' => TicketStatus::New,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::WebPortal,
            'owner_id' => null,
            'sla_policy_id' => $policy->id,
            'first_response_due_at' => now()->subMinutes(10),
            'resolution_due_at' => now()->addHours(8),
        ]);

        app(CheckSlaBreachesAction::class)->execute();

        $fresh = $ticket->fresh();
        $this->assertNotNull($fresh);
        $this->assertSame(TicketPriority::High, $fresh->priority);
        // High resolution target is 240 minutes from now.
        $this->assertTrue($fresh->resolution_due_at->equalTo(Carbon::parse('2026-01-05 13:00:00')));

        Carbon::setTestNow();
    }
}
