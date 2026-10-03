<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Odden\Core\Support\ContactLookup;
use Odden\Marketing\Actions\RegisterContactForEventAction;
use Odden\Marketing\Actions\UpdateAttendanceStatusAction;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingEventRegistration;

class MarketingEventController extends Controller
{
    /**
     * Register a contact for an event or webinar.
     */
    public function register(
        Request $request,
        string $slug,
        RegisterContactForEventAction $registerAction
    ): JsonResponse {
        /** @var MarketingEvent $event */
        $event = MarketingEvent::query()->where('slug', $slug)->where('is_published', true)->firstOrFail();

        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'utm_source' => 'nullable|string|max:255',
            'utm_medium' => 'nullable|string|max:255',
            'utm_campaign' => 'nullable|string|max:255',
        ]);

        $contact = ContactLookup::findOrCreate($validated['email'], [
            'first_name' => $validated['first_name'] ?? 'Attendee',
            'last_name' => $validated['last_name'] ?? '',
        ]);

        // Someone who is already registered can re-submit; only new registrations need an open event with room.
        $alreadyRegistered = MarketingEventRegistration::query()
            ->where('event_id', $event->id)
            ->where('contact_id', $contact->id)
            ->exists();

        if (! $alreadyRegistered) {
            if (! $event->acceptsRegistrations()) {
                return response()->json(['success' => false, 'message' => 'Registration for this event is closed.'], 409);
            }

            if ($event->isFull()) {
                return response()->json(['success' => false, 'message' => 'This event is full.'], 409);
            }
        }

        if (! empty($validated['first_name']) && $contact->first_name === 'Attendee') {
            $contact->update([
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'] ?? $contact->last_name,
            ]);
        }

        $registration = $registerAction->execute(
            event: $event,
            contact: $contact,
            utm: [
                'utm_source' => $validated['utm_source'] ?? null,
                'utm_medium' => $validated['utm_medium'] ?? null,
                'utm_campaign' => $validated['utm_campaign'] ?? null,
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "Successfully registered for {$event->title}",
            'event' => [
                'title' => $event->title,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'virtual_meeting_url' => $event->virtual_meeting_url,
            ],
            'registration' => [
                'id' => $registration->id,
                'status' => $registration->status,
            ],
        ]);
    }

    /**
     * Inbound webhook to mark attendee participation (e.g. from Zoom / Google Meet / Zapier).
     */
    public function attendanceWebhook(
        Request $request,
        string $slug,
        UpdateAttendanceStatusAction $attendanceAction
    ): JsonResponse {
        /** @var MarketingEvent $event */
        $event = MarketingEvent::query()->where('slug', $slug)->firstOrFail();

        $email = (string) $request->input('email');
        if (empty($email)) {
            return response()->json(['error' => 'Email required'], 422);
        }

        $contact = ContactLookup::findByEmail($email);
        if ($contact === null) {
            return response()->json(['error' => 'Contact not found'], 404);
        }

        /** @var MarketingEventRegistration|null $registration */
        $registration = MarketingEventRegistration::query()
            ->where('event_id', $event->id)
            ->where('contact_id', $contact->id)
            ->first();

        if ($registration === null) {
            return response()->json(['error' => 'Registration not found'], 404);
        }

        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(MarketingEventRegistration::STATUSES)],
        ]);
        $status = $validated['status'] ?? 'attended';
        $updated = $attendanceAction->execute($registration, $status);

        return response()->json([
            'success' => true,
            'status' => $updated->status,
            'attended_at' => $updated->attended_at?->toIso8601String(),
        ]);
    }
}
