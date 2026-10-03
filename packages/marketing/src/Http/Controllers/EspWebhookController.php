<?php

declare(strict_types=1);

namespace Odden\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Odden\Marketing\Actions\ProcessEspWebhookAction;

class EspWebhookController extends Controller
{
    /**
     * Handle incoming webhooks from ESPs (Mailgun, SES, Postmark, Resend, Sendgrid, etc.).
     */
    public function handle(Request $request, string $provider, ProcessEspWebhookAction $action): JsonResponse
    {
        /** @var array<string, mixed>|list<array<string, mixed>> $payload */
        $payload = $request->all();

        // SNS posts JSON with a text/plain content type, so the framework doesn't parse it.
        if ($payload === [] && strtolower($provider) === 'ses') {
            $decoded = json_decode($request->getContent(), true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        if (strtolower($provider) === 'ses' && isset($payload['Type'], $payload['TopicArn'])) {
            return $this->handleSnsEnvelope($payload, $action);
        }

        if (array_is_list($payload)) {
            $processed = [];
            foreach ($payload as $single) {
                if (is_array($single)) {
                    $ev = $action->execute($provider, $single);
                    $processed[] = $ev->id;
                }
            }

            return response()->json([
                'status' => 'received',
                'count' => count($processed),
                'event_ids' => $processed,
            ]);
        }

        $event = $action->execute($provider, $payload);

        return response()->json([
            'status' => 'received',
            'event_id' => $event->id,
            'event_type' => $event->event_type,
        ]);
    }

    /**
     * Handle an SNS envelope that wraps SES events.
     *
     * A subscription confirmation is confirmed by calling its `SubscribeURL`, which must be an
     * SNS endpoint on amazonaws.com. A notification's `Message` is the SES event as a JSON string.
     * Anything else (for example an unsubscribe confirmation) is acknowledged and ignored.
     *
     * @param  array<string, mixed>  $envelope
     */
    protected function handleSnsEnvelope(array $envelope, ProcessEspWebhookAction $action): JsonResponse
    {
        $type = (string) $envelope['Type'];

        if ($type === 'SubscriptionConfirmation') {
            $url = (string) ($envelope['SubscribeURL'] ?? '');
            $host = (string) parse_url($url, PHP_URL_HOST);

            if (! str_starts_with($url, 'https://') || ! preg_match('/^sns\.[a-z0-9-]+\.amazonaws\.com(\.cn)?$/i', $host)) {
                return response()->json(['status' => 'ignored', 'message' => 'SubscribeURL is not an SNS endpoint.'], 422);
            }

            Http::timeout(10)->get($url)->throw();

            return response()->json(['status' => 'subscription_confirmed']);
        }

        if ($type === 'Notification') {
            $message = json_decode((string) ($envelope['Message'] ?? ''), true);

            if (! is_array($message)) {
                return response()->json(['status' => 'ignored', 'message' => 'The notification is not an SES event.']);
            }

            $event = $action->execute('ses', $message);

            return response()->json(['status' => 'received', 'event_id' => $event->id, 'event_type' => $event->event_type]);
        }

        return response()->json(['status' => 'ignored']);
    }

    /**
     * Unified generic deliverability endpoint (/api/marketing/webhooks/deliverability).
     */
    public function deliverability(Request $request, ProcessEspWebhookAction $action): JsonResponse
    {
        $provider = (string) $request->input('provider', 'generic');

        return $this->handle($request, $provider, $action);
    }
}
