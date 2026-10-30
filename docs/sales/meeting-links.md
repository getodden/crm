---
title: Meeting links
description: Give reps public booking pages that create contacts and meeting activities.
---

A meeting link is a public page at `/meet/{slug}` where a prospect picks an open slot to meet a rep. Slots come from the link's working hours, duration, buffer, and timezone, and slots the rep already has booked are left out. Booking records the booking, creates or matches the contact, logs a pending meeting activity owned by the rep, stops any active sequences for that contact, and emails a confirmation with an .ics calendar invite to both the visitor and the rep.

## Creating a link

`Odden\Sales\Models\SalesMeetingLink`:

| Attribute | Type | Notes |
| --- | --- | --- |
| `user_id` | int | The rep. Deleting the user deletes their links. |
| `slug` | string | Unique; used in the URL. |
| `title` | string | Defaults to `30 Min Meeting`. |
| `duration_minutes` | int | Defaults to `30`. Length of each meeting; stored in the meeting activity's metadata. |
| `buffer_minutes` | int | Defaults to `0`. Gap kept free between meetings. |
| `description` | text, nullable | Shown on the booking page and in the confirmation. |
| `working_hours` | array, nullable | When the rep can be booked. See [Working hours](#working-hours). Empty uses `odden-sales.meetings.default_working_hours`. |
| `timezone` | string, nullable | Timezone identifier the working hours are in, such as `America/New_York`. Empty or invalid uses `app.timezone`. |
| `is_active` | bool | Defaults to `true`. Inactive links return 404. |

```php
use Odden\Sales\Models\SalesMeetingLink;

$link = SalesMeetingLink::create([
    'user_id' => $rep->id,
    'slug' => 'beth-discovery',
    'title' => 'Discovery call',
    'duration_minutes' => 30,
    'buffer_minutes' => 15,
    'timezone' => 'America/New_York',
    'working_hours' => [
        'monday' => ['09:00-12:00', '13:00-17:00'],
        'wednesday' => ['09:00-17:00'],
        'friday' => ['09:00-12:00'],
    ],
]);

$url = route('odden.meetings.show', ['slug' => $link->slug]); // https://your-app.test/meet/beth-discovery
```

## Working hours

`working_hours` maps lowercase English day names (`monday` to `sunday`) to a list of `"HH:MM-HH:MM"` windows in the link's timezone. Days that are missing or have an empty list can't be booked. The end of a window can be `24:00`. Windows that don't match the format, or that end before they start, are ignored.

```php
'working_hours' => [
    'monday' => ['09:00-12:00', '13:00-17:00'],
    'tuesday' => ['09:00-17:00'],
],
```

A link with no working hours uses `odden-sales.meetings.default_working_hours`, which is Monday to Friday, `09:00-17:00` (see [Configuration](configuration.md#meetings)).

In the [Filament admin](../filament/resources.md#other-sales-resources), the meeting link form has an **Availability** section for the timezone, the buffer, and one input per weekday for its windows. The form rejects windows that don't match the format, and saves no working hours when every day is left empty.

### How slots are computed

`Odden\Sales\Services\MeetingAvailability` works out the open slots:

- Within each window, slots start at the window's start and repeat every `duration_minutes + buffer_minutes`, as long as the meeting ends inside the window. A Monday `09:00-10:30` window with 30-minute meetings and a 15-minute buffer gives 09:00 and 09:45.
- Slots that have already started, dates before today, and dates more than `odden-sales.meetings.booking_window_days` (default 60) ahead are left out.
- A slot is left out if it overlaps one of the rep's active bookings on any of their meeting links, with this link's buffer kept clear before and after.
- One email address can book `odden-sales.meetings.max_bookings_per_email_per_day` (default 3) meetings a day; the booker's name is printed as plain text without links or markup, since the confirmation goes to the address typed in.

```php
use Odden\Sales\Services\MeetingAvailability;

$availability = app(MeetingAvailability::class);

$availability->slotsFor($link, '2030-01-07');         // list of CarbonImmutable starts, in the link's timezone
$availability->isAvailable($link, $startsAt);         // can a meeting start exactly then?
$availability->firstAvailableDate($link);             // "2030-01-07", or null
```

### Busy time outside the app

The package doesn't read external calendars. By default only bookings made through meeting links block slots. To block time the rep is busy elsewhere, such as meetings in an external calendar, bind your own `Odden\Sales\Contracts\ExternalBusyTime`:

```php
use Carbon\CarbonImmutable;
use Odden\Sales\Contracts\ExternalBusyTime;
use Odden\Sales\Models\SalesMeetingLink;

class CalendarBusyTime implements ExternalBusyTime
{
    public function busyPeriods(SalesMeetingLink $link, CarbonImmutable $from, CarbonImmutable $to): array
    {
        // [[startUnix, endUnix], ...] for $link->user_id between $from and $to.
        return [];
    }
}

$this->app->bind(ExternalBusyTime::class, CalendarBusyTime::class);
```

`MeetingAvailability` treats the returned periods like bookings: they remove slots in `slotsFor()` and `firstAvailableDate()`, and `isAvailable()` refuses them, so booking a clashing time throws `MeetingSlotUnavailableException`, including in the re-check under the lock. The link's buffer is kept clear around them. `firstAvailableDate()` asks once for the whole booking window.

Implementations must not throw and should return quickly, because this runs on the public booking page and while booking. Cache inside the implementation, and decide there what a provider outage means. If one throws anyway, the exception is reported and the rep counts as free of external busy time.

## Bookings

Each booking is an `Odden\Sales\Models\SalesMeetingBooking` in `odden_sales_meeting_bookings`:

| Attribute | Notes |
| --- | --- |
| `uid` | UUID, also used as the calendar event's UID. |
| `meeting_link_id` | The link booked. Deleting the link deletes its bookings. |
| `user_id` | The rep (host). |
| `contact_id`, `activity_id` | The contact and the meeting activity created for the booking. |
| `invitee_name`, `invitee_email` | As entered by the visitor (email lower-cased). |
| `starts_at`, `ends_at` | Stored in `app.timezone`. |
| `timezone` | The link's timezone at booking time. Times in the confirmation are shown in it. |
| `cancelled_at` | Set by `$booking->cancel()`. Cancelled bookings no longer block the slot. |

`$link->bookings` lists a link's bookings. Cancelling a booking doesn't change the meeting activity or send any email.

## The booking page

`GET /meet/{slug}` (`odden.meetings.show`) renders `odden-sales::meetings.book` with the open slots for one date, labelled with the link's timezone. The date comes from the `?date=YYYY-MM-DD` query string; without it, the page shows the first date that has open slots. Changing the date reloads the page. If a date has no slots, the page says so and the confirm button is disabled. Override the view at `resources/views/vendor/odden-sales/meetings/book.blade.php` to change the layout; it receives `$link`, `$date`, `$slots` (a list of `CarbonImmutable`), `$timezone`, `$minDate`, and `$maxDate`.

The form posts to `odden.meetings.book` (`POST /meet/{slug}/book`), which is rate limited by Core's `odden-public` limiter and validates:

| Field | Rules |
| --- | --- |
| `name` | required, string, 2–255 characters |
| `email` | required, email, max 255 |
| `phone` | nullable, string, max 30 |
| `date` | required, `Y-m-d` |
| `time` | required, `H:i` |
| `notes` | nullable, string, max 1000 |

The date and time are read in the link's timezone. If that time is not an open slot, for example because someone else booked it a moment earlier, the visitor is sent back with a validation error on `time` and nothing is booked. Otherwise they are redirected to the booking page with a `status` flash message such as "Meeting booked for Monday, January 7 at 9:30 AM (America/New_York). A confirmation with a calendar invite is on its way to dana@example.com."

## What booking does

`Odden\Sales\Actions\BookMeetingAction` does the work, and you can call it directly:

```php
public function execute(
    SalesMeetingLink $link,
    string $fullName,
    string $email,
    CarbonInterface $scheduledAt,
    ?string $phone = null,
    ?string $notes = null,
): array // ['contact' => Contact, 'activity' => Activity, 'booking' => SalesMeetingBooking]
```

```php
use Odden\Sales\Actions\BookMeetingAction;
use Odden\Sales\Exceptions\MeetingSlotUnavailableException;
use Illuminate\Support\Carbon;

try {
    $result = app(BookMeetingAction::class)->execute(
        link: $link,
        fullName: 'Dana Scully',
        email: 'dana@example.com',
        scheduledAt: Carbon::parse('2030-01-07 09:30', $link->timezoneName()),
        phone: '+1 555 0100',
    );
} catch (MeetingSlotUnavailableException $e) {
    // Not an open slot: outside working hours, in the past, too far ahead, or already booked.
}

$result['booking'];  // Odden\Sales\Models\SalesMeetingBooking
$result['contact'];  // Odden\Core\Models\Contact
$result['activity']; // Pending meeting activity
```

Booking happens in two steps.

First the slot is checked with `MeetingAvailability::isAvailable()`; if `$scheduledAt` is not exactly the start of an open slot, `MeetingSlotUnavailableException` is thrown and nothing is written. Then the contact is found or created with `Odden\Core\Support\ContactLookup::findOrCreate()` (see [Looking up contacts by email](../core/contacts-and-companies.md#looking-up-contacts-by-email)):

- The email is trimmed and lower-cased, and an existing contact with that email is reused, ignoring case and surrounding whitespace in the stored address too.
- Otherwise a contact is created through `CreateContactAction`, so `ContactCreated` fires and the email is checked like any other new contact's. It gets the name split on the first space into `first_name` and `last_name`, the visitor's phone, `lead_status` `Open`, and `owner_id` set to the rep. Two bookings of the same new address at once create one contact.
- The visitor's email address is not verified, so an existing contact is never changed: not its name, phone, owner, or anything else.

The contact is resolved before the booking transaction, because a contact created inside an uncommitted transaction would be invisible to a concurrent booking of the same address. If the slot is taken by someone else in the moment between the two steps, the contact stays without a booking.

The rest runs in one database transaction:

1. The rep's meeting link rows are locked (`SELECT ... FOR UPDATE`), so bookings for the same rep happen one at a time, and the slot is checked again. If it is no longer open, `MeetingSlotUnavailableException` is thrown and nothing in this transaction is written. SQLite has no row locks, but its single-writer locking makes a conflicting concurrent booking fail with a database error instead of double-booking.
2. A `meeting` activity is logged on the contact with status `pending`, `due_at` set to the scheduled time, the rep as creator, and the title `{link title} with {contact name}`. The body is `Booked online via /meet/{slug}` when the visitor left no notes. With notes, it is the notes for a contact the booking created; for an existing contact it says the booking came from an unverified visitor, with the name and email they typed, followed by the notes. Metadata holds `duration_minutes` and `booking_slug`.
3. All of the contact's `active` [sequence enrollments](sequences.md#enrollment-status) are set to `unenrolled`.
4. The `SalesMeetingBooking` is created.
5. An `Odden\Sales\Mail\MeetingBookedMail` is queued to the visitor and another to the rep (if the rep's user has an email address). They are dispatched after the transaction commits.

## Confirmation emails

`MeetingBookedMail` implements `ShouldQueue`, so a queue worker must be running. It uses the mailer, queue, and from address in [`odden-sales.mail`](configuration.md#mail). The visitor's copy is titled `Confirmed: {link title} with {rep name} on {date}` with the rep as reply-to; the rep's copy is titled `New booking: {link title} with {visitor name} on {date}` with the visitor as reply-to. The body is the `odden-sales::mail.meeting-booked` view, which you can override like the booking page.

Both copies attach `invite.ics` (`text/calendar; method=REQUEST`, or `method=PUBLISH` as below), built by `Odden\Sales\Services\MeetingInvite`. The event has the booking's `uid`, start and end in UTC, the link title, rep, and visitor as its summary, the rep as organizer, and the visitor as an attendee. If the rep has no email address the invite is sent with `METHOD:PUBLISH` and no organizer. Text values are escaped as RFC 5545 requires, and every line break in them (CRLF, LF, or a lone CR) becomes an escaped `\n`, so a visitor's name or a link description can't add lines to the invite.
