<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketSource;
use Odden\Service\Models\CannedResponse;
use Odden\Service\Models\Ticket;
use Odden\Service\Tests\Fixtures\User;

class CannedResponseTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_create_and_manage_canned_responses(): void
    {
        $agent = User::factory()->create();

        $canned = CannedResponse::create([
            'title' => 'Request More Information',
            'shortcut' => '!moreinfo',
            'category' => 'Troubleshooting',
            'content' => 'Could you please share your browser version and relevant screenshots?',
            'user_id' => $agent->id,
            'is_shared' => true,
        ]);

        $this->assertSame('!moreinfo', $canned->shortcut);
        $this->assertTrue($canned->is_shared);
        $this->assertSame($agent->id, $canned->user_id);
    }

    public function test_available_to_returns_shared_responses_and_the_agents_own(): void
    {
        $me = User::factory()->create();
        $colleague = User::factory()->create();

        $shared = CannedResponse::create(['title' => 'Shared', 'shortcut' => '!s', 'category' => 'General', 'content' => 'x', 'user_id' => $colleague->id, 'is_shared' => true]);
        $mine = CannedResponse::create(['title' => 'Mine', 'shortcut' => '!m', 'category' => 'General', 'content' => 'x', 'user_id' => $me->id, 'is_shared' => false]);
        CannedResponse::create(['title' => 'Theirs', 'shortcut' => '!t', 'category' => 'General', 'content' => 'x', 'user_id' => $colleague->id, 'is_shared' => false]);

        $ids = CannedResponse::query()->availableTo($me->id)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$shared->id, $mine->id], $ids);
        $this->assertSame([$shared->id], CannedResponse::query()->availableTo(null)->pluck('id')->all());
    }

    public function test_render_fills_variables_and_leaves_unknown_tags(): void
    {
        $agent = User::factory()->create(['name' => 'Dana Agent']);
        $company = Company::factory()->create(['name' => 'Black Mesa']);
        $contact = Contact::factory()->create(['first_name' => 'Gordon', 'last_name' => 'Freeman', 'email' => 'gordon@blackmesa.com']);
        $ticket = Ticket::create(['subject' => 'Cannot log in', 'contact_id' => $contact->id, 'company_id' => $company->id]);

        $canned = CannedResponse::create([
            'title' => 'Greeting',
            'shortcut' => '!hi',
            'category' => 'General',
            'content' => 'Hi {{contact.first_name}} ({{ contact.email }}) at {{company.name}}, re {{ticket.subject}} #{{ticket.number}}. {{agent.name}} here. {{unknown.tag}} {{contact.phone}}',
        ]);

        $this->assertSame(
            "Hi Gordon (gordon@blackmesa.com) at Black Mesa, re Cannot log in #{$ticket->ticket_number}. Dana Agent here. {{unknown.tag}} {{contact.phone}}",
            $canned->render($ticket->fresh(), $agent)
        );
        $this->assertSame('Hi {{contact.first_name}}', CannedResponse::create(['title' => 'Bare', 'shortcut' => '!b', 'category' => 'General', 'content' => 'Hi {{contact.first_name}}'])->render());
    }

    public function test_new_tickets_use_the_configured_default_priority_and_source(): void
    {
        config(['odden-service.defaults.priority' => 'high', 'odden-service.defaults.source' => 'email']);

        $ticket = Ticket::create(['subject' => 'Defaults']);

        $this->assertSame(TicketPriority::High, $ticket->priority);
        $this->assertSame(TicketSource::Email, $ticket->source);
        $this->assertSame(TicketPriority::High, $ticket->fresh()->priority);

        // Explicit values still win.
        $explicit = Ticket::create(['subject' => 'Explicit', 'priority' => TicketPriority::Low, 'source' => TicketSource::Chat]);
        $this->assertSame(TicketPriority::Low, $explicit->priority);
        $this->assertSame(TicketSource::Chat, $explicit->source);
    }

    public function test_tickets_can_be_scoped_to_a_team(): void
    {
        $mine = Ticket::create(['subject' => 'Team 1', 'team_id' => 1]);
        Ticket::create(['subject' => 'Team 2', 'team_id' => 2]);

        $this->assertSame([$mine->id], Ticket::query()->forTeam(1)->pluck('id')->all());
    }
}
