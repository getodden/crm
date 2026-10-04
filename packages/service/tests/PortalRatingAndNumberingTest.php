<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Odden\Core\Models\Contact;
use Odden\Service\Actions\RouteTicketAction;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\Ticket;
use Odden\Service\Models\TicketRoutingRule;

class PortalRatingAndNumberingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ticket(array $attributes = []): Ticket
    {
        $contact = Contact::create(['first_name' => 'Cus', 'email' => 'cus@example.com']);

        return Ticket::create($attributes + [
            'subject' => 'Help',
            'status' => TicketStatus::Resolved,
            'priority' => TicketPriority::Medium,
            'source' => TicketSource::WebPortal,
            'contact_id' => $contact->id,
        ]);
    }

    public function test_a_resolved_ticket_is_rated_once_and_a_second_rating_changes_nothing(): void
    {
        $ticket = $this->ticket();
        $url = route('odden.support.rate', ['token' => $ticket->portal_token]);

        $this->post($url, ['rating' => 1, 'comment' => 'Slow'])->assertRedirect();
        $this->assertSame(1, $ticket->fresh()?->csat_rating);
        $notes = $ticket->messages()->where('is_internal_note', true)->count();
        $tasks = $ticket->contact->activities()->count();

        // The same link again, with a different rating, adds no note or task and does not rewrite the score.
        $this->post($url, ['rating' => 5, 'comment' => 'Actually great'])->assertRedirect()->assertSessionHas('status');
        $this->post($url, ['rating' => 1])->assertRedirect();

        $fresh = $ticket->fresh();
        $this->assertSame(1, $fresh?->csat_rating);
        $this->assertSame('Slow', $fresh?->csat_comment);
        $this->assertSame($notes, $ticket->messages()->where('is_internal_note', true)->count());
        $this->assertSame($tasks, $ticket->contact->activities()->count());
    }

    public function test_an_open_ticket_cannot_be_rated(): void
    {
        $ticket = $this->ticket(['status' => TicketStatus::Open]);

        $this->post(route('odden.support.rate', ['token' => $ticket->portal_token]), ['rating' => 1])->assertSessionHasErrors('rating');

        $this->assertNull($ticket->fresh()?->csat_rating);
    }

    public function test_a_ticket_number_that_is_already_taken_is_not_reused(): void
    {
        $prefix = (string) config('odden-service.defaults.prefix', 'TICK');
        $first = $this->ticket(['ticket_number' => $prefix.'-'.now()->format('Y').'-AAAAA']);
        $sequence = ['AAAAA', 'BBBBB'];
        Str::createRandomStringsUsing(function (int $length) use (&$sequence): string {
            return $length === 5 ? (array_shift($sequence) ?? 'ZZZZZ') : str_repeat('t', $length);
        });

        try {
            $second = Ticket::create(['subject' => 'Another', 'status' => TicketStatus::New, 'priority' => TicketPriority::Low, 'source' => TicketSource::WebPortal, 'contact_id' => $first->contact_id]);
        } finally {
            Str::createRandomStringsNormally();
        }

        $this->assertSame($prefix.'-'.now()->format('Y').'-BBBBB', $second->ticket_number);
    }

    public function test_the_round_robin_pointer_advances_through_the_pool(): void
    {
        $rule = TicketRoutingRule::create(['name' => 'Everyone', 'is_active' => true, 'criteria' => [], 'assigned_user_ids' => [10, 20, 30], 'last_assigned_index' => -1]);
        $action = new class extends RouteTicketAction
        {
            /**
             * @param  list<int>  $pool
             */
            public function pick(TicketRoutingRule $rule, array $pool): ?int
            {
                return $this->selectUser($rule, $pool);
            }
        };

        $picks = [$action->pick($rule, [10, 20, 30]), $action->pick($rule, [10, 20, 30]), $action->pick($rule, [10, 20, 30]), $action->pick($rule, [10, 20, 30])];

        $this->assertSame([10, 20, 30, 10], $picks);
        $this->assertSame(0, $rule->fresh()?->last_assigned_index);
    }
}
