<?php

declare(strict_types=1);

namespace Odden\Sales\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Odden\Core\Support\UserModel;
use Odden\Sales\Enums\MeetingCancelledBy;
use Odden\Sales\Models\SalesMeetingBooking;
use Odden\Sales\Services\MeetingInvite;

/**
 * Tells the invitee or the host that a booked meeting was cancelled, with an .ics (METHOD:CANCEL) that removes it from their calendar.
 */
class MeetingCancelledMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use ConfiguresSalesMail, Queueable, SerializesModels;

    /**
     * @param  'invitee'|'host'  $recipientRole
     */
    public function __construct(
        public SalesMeetingBooking $booking,
        public string $recipientRole = 'invitee',
        public MeetingCancelledBy $cancelledBy = MeetingCancelledBy::Staff,
        public ?string $actorName = null,
        public ?string $reason = null,
    ) {
        $this->configureSalesMail();
    }

    public function envelope(): Envelope
    {
        $booking = $this->booking;
        $hostName = UserModel::displayName($booking->user, 'your host');
        $when = $booking->starts_at->copy()->setTimezone($booking->timezone)->format('D, M j \a\t g:i A');

        $from = self::configuredFrom();
        $hostEmail = $booking->user->getAttribute('email');

        $replyTo = $this->recipientRole === 'host'
            ? [new Address($booking->invitee_email, $booking->invitee_name)]
            : (is_string($hostEmail) && $hostEmail !== '' ? [new Address($hostEmail, $hostName)] : []);

        return new Envelope(
            from: $from !== null ? new Address($from[0], $from[1]) : null,
            replyTo: $replyTo,
            subject: "Cancelled: {$booking->meetingLink->title} on {$when}",
        );
    }

    public function content(): Content
    {
        $booking = $this->booking;
        $hostName = UserModel::displayName($booking->user, 'your host');

        return new Content(
            view: 'odden-sales::mail.meeting-cancelled',
            with: [
                'booking' => $booking,
                'link' => $booking->meetingLink,
                'hostName' => $hostName,
                'recipientRole' => $this->recipientRole,
                'cancelledByName' => $this->cancelledByName($hostName),
                'reason' => $this->reason !== null && trim($this->reason) !== '' ? trim($this->reason) : null,
                'startsAt' => $booking->starts_at->copy()->setTimezone($booking->timezone),
                'endsAt' => $booking->ends_at->copy()->setTimezone($booking->timezone),
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => app(MeetingInvite::class)->cancelIcs($this->booking), 'cancelled.ics')
                ->withMime('text/calendar; charset=UTF-8; method=CANCEL'),
        ];
    }

    private function cancelledByName(string $hostName): string
    {
        if ($this->cancelledBy === MeetingCancelledBy::Invitee) {
            return $this->recipientRole === 'host' ? $this->booking->invitee_name : 'you';
        }

        $actor = $this->actorName !== null && trim($this->actorName) !== '' ? trim($this->actorName) : $hostName;

        return $actor;
    }
}
