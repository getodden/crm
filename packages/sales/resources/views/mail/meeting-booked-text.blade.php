{{-- Plain text: nothing here is HTML, so values are printed as they are. --}}
@if ($recipientRole === 'host')
{!! $booking->invitee_name !!} ({!! $booking->invitee_email !!}) booked {!! $link->title !!} with you.
@else
Hi {!! $booking->invitee_name !!},

Your meeting {!! $link->title !!} with {!! $hostName !!} of {!! config('app.name') !!} is confirmed.
@endif

{!! $startsAt->format('l, F j, Y') !!}
{!! $startsAt->format('g:i A') !!} - {!! $endsAt->format('g:i A') !!} ({!! $booking->timezone !!})

The attached calendar invite (invite.ics) adds the meeting to your calendar.
@if ($recipientRole !== 'host')

Need another time? Reply to this email.
@endif
