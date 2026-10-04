<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Service\Actions\CheckSlaBreachesAction;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\SlaPolicy;
use Odden\Service\Models\Ticket;

class SlaClockTest extends TestCase
{
    use RefreshDatabase;

    private function policy(): SlaPolicy
    {
        return SlaPolicy::create([
            'name' => 'Standard',
            'is_default' => false,
            'is_active' => true,
            'urgent_first_response_minutes' => 15,
            'urgent_resolution_minutes' => 60,
            'high_first_response_minutes' => 30,
            'high_resolution_minutes' => 120,
            'medium_first_response_minutes' => 60,
            'medium_resolution_minutes' => 1440,
            'low_first_response_minutes' => 120,
            'low_resolution_minutes' => 2880,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticket(SlaPolicy $policy, array $attributes = []): Ticket
    {
        return Ticket::create($attributes + [
            'subject' => 'Printer on fire',
            'status' => TicketStatus::Open,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::WebPortal,
            'sla_policy_id' => $policy->id,
        ]);
    }

    public function test_a_reopened_ticket_gets_a_fresh_resolution_clock_and_is_not_flagged_at_once(): void
    {
        $policy = $this->policy();
        $ticket = $this->ticket($policy, [
            'status' => TicketStatus::Resolved,
            'resolved_at' => now()->subDays(2),
            'resolution_due_at' => now()->subDays(1),
        ]);
        $ticket->forceFill(['created_at' => now()->subDays(3)])->saveQuietly();

        $ticket->addMessage('It broke again', MessageSenderType::Customer);

        $fresh = $ticket->fresh();
        $this->assertSame(TicketStatus::WaitingOnAgent, $fresh?->status);
        $this->assertNull($fresh?->resolved_at);
        $this->assertTrue($fresh?->resolution_due_at?->isFuture(), 'The new due date counts from the reopen');
        $this->assertEqualsWithDelta(1440, now()->diffInMinutes($fresh?->resolution_due_at), 2);

        $result = app(CheckSlaBreachesAction::class)->execute();

        $this->assertSame(0, $result['resolution_breaches']);
        $this->assertFalse((bool) $ticket->fresh()?->is_sla_resolution_breached);
    }

    public function test_waiting_on_the_customer_pauses_the_resolution_clock(): void
    {
        $policy = $this->policy();
        $ticket = $this->ticket($policy, ['resolution_due_at' => now()->addHour()]);
        $originalDue = $ticket->resolution_due_at;

        // An agent replies: the ticket now waits on the customer and the clock stops.
        $ticket->addMessage('Can you send a photo?', MessageSenderType::Agent);
        $this->assertSame(TicketStatus::WaitingOnCustomer, $ticket->fresh()?->status);
        $this->assertNotNull($ticket->fresh()?->sla_paused_at);

        // Three hours go by with no answer: past the old due date, but not the agents' fault.
        $this->travel(3)->hours();
        $result = app(CheckSlaBreachesAction::class)->execute();
        $this->assertSame(0, $result['resolution_breaches']);
        $this->assertFalse((bool) $ticket->fresh()?->is_sla_resolution_breached);

        // The customer answers: the due date moves out by the three hours spent waiting.
        $ticket->fresh()?->addMessage('Here it is', MessageSenderType::Customer);

        $fresh = $ticket->fresh();
        $this->assertNull($fresh?->sla_paused_at);
        $this->assertEqualsWithDelta(3 * 3600, $originalDue->diffInSeconds($fresh?->resolution_due_at), 5);
        $this->assertTrue($fresh?->resolution_due_at?->isFuture());
    }

    public function test_a_status_change_in_the_panel_pauses_and_resumes_the_clock_too(): void
    {
        $policy = $this->policy();
        $ticket = $this->ticket($policy, ['resolution_due_at' => now()->addHours(2)]);
        $originalDue = $ticket->resolution_due_at;

        $ticket->update(['status' => TicketStatus::WaitingOnCustomer]);
        $this->assertNotNull($ticket->fresh()?->sla_paused_at);

        $this->travel(90)->minutes();
        $ticket->fresh()?->update(['status' => TicketStatus::WaitingOnAgent]);

        $fresh = $ticket->fresh();
        $this->assertNull($fresh?->sla_paused_at);
        $this->assertEqualsWithDelta(90 * 60, $originalDue->diffInSeconds($fresh?->resolution_due_at), 5);
    }

    public function test_an_unassigned_ticket_is_escalated_once_per_run_even_when_both_clocks_are_late(): void
    {
        $policy = $this->policy();
        $ticket = $this->ticket($policy, [
            'priority' => TicketPriority::Low,
            'first_response_due_at' => now()->subDays(6),
            'resolution_due_at' => now()->subDays(5),
        ]);
        $ticket->forceFill(['created_at' => now()->subDays(7)])->saveQuietly();

        $result = app(CheckSlaBreachesAction::class)->execute();

        $this->assertSame(1, $result['response_breaches']);
        $this->assertSame(TicketPriority::Medium, $ticket->fresh()?->priority, 'One step up, not two');
        $this->assertSame(1, $ticket->messages()->where('is_internal_note', true)->count(), 'One alert note');
    }

    public function test_an_already_claimed_breach_is_not_notified_again(): void
    {
        $policy = $this->policy();
        $this->ticket($policy, ['first_response_due_at' => now()->subMinutes(5)]);

        $first = app(CheckSlaBreachesAction::class)->execute();
        $second = app(CheckSlaBreachesAction::class)->execute();

        $this->assertSame(1, $first['response_breaches']);
        $this->assertSame(0, $second['response_breaches']);
    }
}
