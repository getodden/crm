<?php

declare(strict_types=1);

namespace Odden\Service\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Odden\Core\Models\Contact;
use Odden\Service\Actions\CheckSlaBreachesAction;
use Odden\Service\Actions\CreateTicketAction;
use Odden\Service\Actions\ReplyTicketAction;
use Odden\Service\Actions\ResolveTicketAction;
use Odden\Service\Enums\MessageSenderType;
use Odden\Service\Enums\TicketPriority;
use Odden\Service\Enums\TicketStatus;
use Odden\Service\Models\Ticket;
use Odden\Service\Notifications\SlaBreachAlertNotification;
use Odden\Service\Notifications\TicketCreatedNotification;
use Odden\Service\Notifications\TicketRepliedNotification;
use Odden\Service\Notifications\TicketResolvedCsatNotification;
use Odden\Service\Tests\Fixtures\User;

class TicketNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_receives_confirmation_notification_when_ticket_is_created(): void
    {
        Notification::fake();

        $contact = Contact::factory()->create([
            'first_name' => 'Gordon',
            'last_name' => 'Freeman',
            'email' => 'gordon@blackmesa.gov',
        ]);

        $ticket = (new CreateTicketAction)->execute(
            subject: 'HEV suit power failure',
            description: 'The auxiliary power system is depleted.',
            priority: TicketPriority::High,
            contact: $contact
        );

        Notification::assertSentTo(
            $contact,
            TicketCreatedNotification::class,
            function (TicketCreatedNotification $notification) use ($ticket) {
                return $notification->ticket->id === $ticket->id;
            }
        );
    }

    public function test_customer_receives_notification_when_agent_posts_public_reply(): void
    {
        Notification::fake();

        $contact = Contact::factory()->create([
            'first_name' => 'Alyx',
            'last_name' => 'Vance',
            'email' => 'alyx@city17.resistance',
        ]);

        $agent = User::factory()->create(['name' => 'Barney Calhoun']);

        $ticket = Ticket::create([
            'subject' => 'EMP tool recharge required',
            'contact_id' => $contact->id,
            'owner_id' => $agent->id,
        ]);

        (new ReplyTicketAction)->execute(
            ticket: $ticket,
            body: 'We dispatched a replacement battery pack.',
            senderType: MessageSenderType::Agent,
            user: $agent,
            isInternalNote: false
        );

        Notification::assertSentTo(
            $contact,
            TicketRepliedNotification::class,
            function (TicketRepliedNotification $notification) use ($ticket) {
                return $notification->ticket->id === $ticket->id
                    && str_contains($notification->message->body, 'replacement battery pack');
            }
        );
    }

    public function test_customer_does_not_receive_notification_on_internal_note(): void
    {
        Notification::fake();

        $contact = Contact::factory()->create([
            'first_name' => 'Eli',
            'last_name' => 'Vance',
            'email' => 'eli@blackmesa.gov',
        ]);

        $agent = User::factory()->create();

        $ticket = Ticket::create([
            'subject' => 'Teleport gate alignment',
            'contact_id' => $contact->id,
            'owner_id' => $agent->id,
        ]);

        (new ReplyTicketAction)->execute(
            ticket: $ticket,
            body: 'Internal note: Xen crystals are fluctuating.',
            senderType: MessageSenderType::Agent,
            user: $agent,
            isInternalNote: true
        );

        Notification::assertNothingSent();
    }

    public function test_customer_receives_csat_survey_notification_when_ticket_is_resolved(): void
    {
        Notification::fake();

        $contact = Contact::factory()->create([
            'first_name' => 'Wallace',
            'last_name' => 'Breen',
            'email' => 'breen@citadel.gov',
        ]);

        $ticket = Ticket::create([
            'subject' => 'Broadcast frequency issue',
            'contact_id' => $contact->id,
            'status' => TicketStatus::Open,
        ]);

        (new ResolveTicketAction)->execute(
            ticket: $ticket,
            resolutionNote: 'Re-aligned communications array.'
        );

        Notification::assertSentTo(
            $contact,
            TicketResolvedCsatNotification::class,
            function (TicketResolvedCsatNotification $notification) use ($ticket) {
                return $notification->ticket->id === $ticket->id
                    && $notification->resolutionNote === 'Re-aligned communications array.';
            }
        );
    }

    public function test_agent_receives_sla_breach_alert_notification(): void
    {
        Notification::fake();

        $agent = User::factory()->create();

        $ticket = Ticket::create([
            'subject' => 'Reactor containment breach',
            'status' => TicketStatus::Open,
            'owner_id' => $agent->id,
            'first_response_due_at' => now()->subHour(),
            'is_sla_response_breached' => false,
        ]);

        (new CheckSlaBreachesAction)->execute();

        Notification::assertSentTo(
            $agent,
            SlaBreachAlertNotification::class,
            function (SlaBreachAlertNotification $notification) use ($ticket) {
                return $notification->ticket->id === $ticket->id
                    && $notification->breachType === 'first_response';
            }
        );
    }

    public function test_customer_controlled_text_is_not_rendered_as_markdown_in_ticket_emails(): void
    {
        $agent = User::factory()->create(['name' => 'Agent *Bold* [Click](https://evil.example)']);
        $contact = Contact::factory()->create(['email' => 'victim@corp.test']);
        $ticket = Ticket::create([
            'subject' => "Live Chat inquiry from [Reset](https://evil.example) **now**\n\n# Urgent <b>html</b>",
            'contact_id' => $contact->id,
            'owner_id' => $agent->id,
            'status' => TicketStatus::Open,
        ]);
        $message = $ticket->addMessage('We are looking into it.', MessageSenderType::Agent);

        $mails = [
            (new TicketCreatedNotification($ticket))->toMail($contact),
            (new TicketRepliedNotification($ticket, $message))->toMail($contact),
            (new TicketResolvedCsatNotification($ticket, 'Done.'))->toMail($contact),
            (new SlaBreachAlertNotification($ticket, 'first_response'))->toMail($agent),
        ];

        foreach ($mails as $index => $mail) {
            $html = (string) $mail->render();

            $this->assertStringNotContainsString('href="https://evil.example"', $html);
            $this->assertStringNotContainsString('<strong>now</strong>', $html);
            $this->assertStringNotContainsString('<h1>Urgent', $html);
            $this->assertStringNotContainsString('<b>html</b>', $html);

            if ($index !== 1) {
                // The reply email shows the subject only in its Subject header.
                $this->assertStringContainsString('[Reset](https://evil.example) **now** # Urgent', $html);
            }
        }

        $sla = (string) $mails[3]->render();
        $this->assertStringNotContainsString('<em>Bold</em>', $sla);
        $this->assertStringContainsString('Agent *Bold* [Click](https://evil.example)', $sla);
    }

    public function test_sla_breach_alert_links_to_the_configured_admin_url(): void
    {
        $agent = User::factory()->create();
        $ticket = Ticket::create(['subject' => 'Link check', 'status' => TicketStatus::Open, 'owner_id' => $agent->id]);
        $alert = new SlaBreachAlertNotification($ticket, 'resolution');

        $default = $alert->toMail($agent);
        $this->assertSame(url("/admin/tickets/{$ticket->id}/edit"), $default->actionUrl);

        config(['odden-service.admin_ticket_url' => '/panel/support/{id}']);
        $this->assertSame(url("/panel/support/{$ticket->id}"), $alert->toMail($agent)->actionUrl);

        SlaBreachAlertNotification::resolveUrlUsing(fn (Ticket $t): string => "https://helpdesk.test/t/{$t->ticket_number}");

        try {
            $this->assertSame("https://helpdesk.test/t/{$ticket->ticket_number}", $alert->toMail($agent)->actionUrl);
        } finally {
            SlaBreachAlertNotification::resolveUrlUsing(null);
        }
    }
}
