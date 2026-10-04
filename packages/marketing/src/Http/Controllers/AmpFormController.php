<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Odden\Marketing\Actions\SubmitNpsResponseAction;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingEventRegistration;
use Odden\Marketing\Models\NpsResponse;
use Odden\Marketing\Support\ContactToken;

class AmpFormController extends Controller
{
    /**
     * Handle in-email AMP 1-click rating or feedback form submission.
     */
    public function feedback(Request $request): JsonResponse
    {
        $this->ensureAllowedOrigin($request);

        $validated = $request->validate([
            'token' => 'nullable|string|max:255',
            'score' => 'required|integer|min:0|max:10',
            'feedback' => 'nullable|string|max:2000',
            'email' => 'nullable|email|max:255',
        ]);

        $score = (int) $validated['score'];
        $feedback = isset($validated['feedback']) ? (string) $validated['feedback'] : null;
        $token = isset($validated['token']) ? (string) $validated['token'] : null;

        if (! empty($token)) {
            $nps = NpsResponse::query()->where('token', $token)->first();
            if ($nps !== null) {
                // The same path as the email links, so the category, the contact's properties and the lead
                // score are set. A rating already given is kept (a retry or a second tab is not a new answer);
                // comments can still be added to it.
                if ($nps->responded_at === null) {
                    app(SubmitNpsResponseAction::class)->execute($nps, $score, $feedback);
                } elseif ($feedback !== null) {
                    $nps->update(['feedback' => $feedback]);
                }
            }
        }

        return $this->ampResponse($request, [
            'status' => 'success',
            'message' => 'Thank you! Your feedback has been recorded.',
            'score' => $score,
        ]);
    }

    /**
     * Handle in-email AMP event RSVP form submission.
     *
     * The contact comes only from the signed token issued for this recipient and
     * event (MarketingEvent::rsvpTokenFor()); a submitted email is never trusted.
     */
    public function rsvp(Request $request): JsonResponse
    {
        $this->ensureAllowedOrigin($request);

        $validated = $request->validate([
            'event_slug' => 'required|string|max:255',
            'token' => 'required|string|max:255',
            'status' => 'nullable|string|in:attending,declined,tentative',
        ]);

        $eventSlug = (string) $validated['event_slug'];
        $status = isset($validated['status']) ? (string) $validated['status'] : 'attending';

        $event = MarketingEvent::query()->where('slug', $eventSlug)->first();
        $contact = $event !== null ? ContactToken::resolve($validated['token'], ContactToken::forEvent($event->id)) : null;

        if ($event === null || $contact === null) {
            return $this->ampResponse($request, [
                'status' => 'error',
                'message' => 'This RSVP link is invalid or has expired.',
            ], 403);
        }

        MarketingEventRegistration::query()->updateOrCreate(
            [
                'event_id' => $event->id,
                'contact_id' => $contact->id,
            ],
            [
                'status' => $status,
                'registered_at' => now(),
            ]
        );

        return $this->ampResponse($request, [
            'status' => 'success',
            'message' => 'Your RSVP has been saved successfully.',
            'event' => $eventSlug,
            'rsvp_status' => $status,
        ]);
    }

    /**
     * Reject requests that don't come from an allow-listed AMP for Email client.
     */
    protected function ensureAllowedOrigin(Request $request): void
    {
        $origin = $request->header('Origin');
        $allowed = (array) config('odden-marketing.amp.allowed_origins', []);

        abort_unless(is_string($origin) && in_array($origin, $allowed, true), 403);
    }

    /**
     * Return a JsonResponse with AMP for Email CORS headers (spec version 2).
     *
     * @param  array<string, mixed>  $data
     */
    protected function ampResponse(Request $request, array $data, int $status = 200): JsonResponse
    {
        $response = response()->json($data, $status);

        $response->headers->set('Access-Control-Allow-Origin', (string) $request->header('Origin'));
        $response->headers->set('Access-Control-Expose-Headers', 'AMP-Email-Allow-Sender');

        $sender = $request->header('AMP-Email-Sender');
        if (is_string($sender) && $sender !== '') {
            $response->headers->set('AMP-Email-Allow-Sender', $sender);
        }

        return $response;
    }
}
