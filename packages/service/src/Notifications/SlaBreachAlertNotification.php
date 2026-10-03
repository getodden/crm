<?php

declare(strict_types=1);

namespace Odden\Service\Notifications;

use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Odden\Core\Support\UserModel;
use Odden\Service\Models\Ticket;
use Odden\Service\Notifications\Concerns\UsesServiceNotificationQueue;
use Odden\Service\Support\MailMarkdown;

class SlaBreachAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;
    use UsesServiceNotificationQueue;

    /** @var (Closure(Ticket): string)|null */
    protected static ?Closure $urlResolver = null;

    public function __construct(
        public Ticket $ticket,
        public string $breachType // 'first_response' or 'resolution'
    ) {
        $this->useServiceNotificationQueue();
    }

    /**
     * @return array<int, string>
     */
    public function via(mixed $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(mixed $notifiable): MailMessage
    {
        $typeName = $this->breachType === 'first_response' ? 'First Response' : 'Resolution';
        $subject = "[URGENT SLA BREACH] Ticket #{$this->ticket->ticket_number}: {$this->ticket->subject}";

        $agentName = UserModel::displayName($this->ticket->owner, 'Unassigned');

        return (new MailMessage)
            ->error()
            ->subject($subject)
            ->greeting('Attention Support Team,')
            ->line("An SLA target has been breached on support ticket #{$this->ticket->ticket_number}.")
            ->line("**Breach Type:** {$typeName} SLA Target Exceeded")
            ->line('**Priority:** '.$this->ticket->priority->getLabel())
            ->line('**Subject:** '.MailMarkdown::escape($this->ticket->subject))
            ->line('**Assigned Agent:** '.MailMarkdown::escape($agentName))
            ->action('Open Ticket in Cockpit', $this->ticketUrl())
            ->line("Please take immediate action to address this customer's inquiry.");
    }

    /**
     * Resolve the ticket link through a callback instead of the `admin_ticket_url` config value.
     *
     * @param  (Closure(Ticket): string)|null  $callback
     */
    public static function resolveUrlUsing(?Closure $callback): void
    {
        static::$urlResolver = $callback;
    }

    protected function ticketUrl(): string
    {
        if (static::$urlResolver !== null) {
            return (string) (static::$urlResolver)($this->ticket);
        }

        $template = (string) config('odden-service.admin_ticket_url', '/admin/tickets/{id}/edit');

        return url(str_replace('{id}', (string) $this->ticket->id, $template));
    }
}
