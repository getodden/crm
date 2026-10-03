<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Enums\SubscriptionStatus;
use Odden\Marketing\Mail\MarketingMessageMailable;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\EmailSuppression;
use Odden\Marketing\Models\EspEvent;
use Odden\Marketing\Models\MarketingSubscription;

class ProcessEspWebhookAction
{
    /**
     * Process an incoming email service provider deliverability webhook.
     *
     * @param  array<string|int, mixed>  $payload
     */
    public function execute(string $provider, array $payload): EspEvent
    {
        $normalized = $this->normalizePayload($provider, $payload);

        $email = mb_strtolower(trim($normalized['email']));
        $eventType = $normalized['event_type'];

        /** @var CampaignRecipient|null $recipient */
        $recipient = null;
        if (! empty($normalized['tracking_token'])) {
            $recipient = CampaignRecipient::query()->where('tracking_token', $normalized['tracking_token'])->first();
        }
        if ($recipient === null && ! empty($email)) {
            $recipient = CampaignRecipient::query()
                ->where('email', $email)
                ->latest('id')
                ->first();
        }

        /** @var EspEvent $event */
        $event = EspEvent::create([
            'provider' => $provider,
            'event_type' => $eventType,
            'email' => $email,
            'campaign_id' => $recipient?->campaign_id,
            'recipient_id' => $recipient?->id,
            'error_code' => $normalized['error_code'] ?? null,
            'error_message' => $normalized['error_message'] ?? null,
            'payload' => $payload,
            'created_at' => now(),
        ]);

        // Auto-suppress and handle bounces or spam complaints
        if (in_array($eventType, ['bounce', 'hard_bounce', 'complaint', 'spam', 'unsubscribed'], true)) {
            $status = in_array($eventType, ['bounce', 'hard_bounce'], true)
                ? SubscriptionStatus::Bounced
                : SubscriptionStatus::Unsubscribed;

            MarketingSubscription::updateOrCreate(
                ['email' => $email],
                [
                    'contact_id' => $recipient?->contact_id,
                    'status' => $status,
                    'unsubscribed_at' => now(),
                ]
            );

            // Register in global deliverability suppression registry
            $suppressionReason = match ($eventType) {
                'complaint', 'spam' => 'spam_complaint',
                'unsubscribed' => 'unsubscribe',
                default => 'hard_bounce',
            };
            EmailSuppression::suppress(
                email: $email,
                reason: $suppressionReason,
                source: "esp_webhook:{$provider}",
                metadata: [
                    'campaign_id' => $recipient?->campaign_id,
                    'error_code' => $normalized['error_code'] ?? null,
                    'error_message' => $normalized['error_message'] ?? null,
                ]
            );

            if ($recipient !== null) {
                $recipientStatus = ($status === SubscriptionStatus::Bounced)
                    ? RecipientStatus::Bounced
                    : RecipientStatus::Unsubscribed;

                $recipient->update(['status' => $recipientStatus]);

                if ($recipientStatus === RecipientStatus::Bounced) {
                    $recipient->campaign->increment('bounces_count');
                } elseif ($recipientStatus === RecipientStatus::Unsubscribed) {
                    $recipient->campaign->increment('unsubscribes_count');
                }

                // Deduct lead scoring if spam complaint or hard bounce
                if ($recipient->contact !== null) {
                    app(ApplyLeadScoringEventAction::class)->execute(
                        contact: $recipient->contact,
                        eventType: LeadScoringEventType::Unsubscribed,
                        description: "ESP Deliverability Event: {$eventType} reported by {$provider}",
                    );
                }
            }
        } elseif ($eventType === 'delivered' && $recipient !== null && $recipient->status === RecipientStatus::Pending) {
            $recipient->update(['status' => RecipientStatus::Sent, 'sent_at' => now()]);
        }

        return $event;
    }

    /**
     * Map Mailgun's event names onto the ones this action acts on. Mailgun reports a bounce as
     * "failed" with a severity, and a spam complaint as "complained". Only a permanent failure
     * is a hard bounce; a temporary one is kept as a soft bounce and suppresses nobody.
     *
     * @param  array<string|int, mixed>  $payload
     */
    protected function mailgunEventType(array $payload): string
    {
        $event = strtolower((string) ($payload['event-data']['event'] ?? ($payload['event'] ?? 'unknown')));
        $severity = strtolower((string) ($payload['event-data']['severity'] ?? ''));

        return match (true) {
            $event === 'failed' && $severity === 'permanent' => 'hard_bounce',
            $event === 'failed' => 'soft_bounce',
            $event === 'complained' => 'complaint',
            default => $event,
        };
    }

    /**
     * SES event types. An event publishing record has `eventType`, a legacy notification has
     * `notificationType`. A permanent bounce is a hard bounce and a transient one a soft bounce;
     * anything we don't recognize, or an event without a type, is "unknown" and is only stored.
     *
     * @param  array<string|int, mixed>  $payload
     */
    protected function sesEventType(array $payload): string
    {
        $type = strtolower((string) ($payload['eventType'] ?? ($payload['notificationType'] ?? ($payload['event_type'] ?? ''))));
        $bounceType = strtolower((string) ($payload['bounce']['bounceType'] ?? ''));

        return match (true) {
            $type === 'bounce' && $bounceType === 'permanent' => 'hard_bounce',
            $type === 'bounce' && $bounceType !== '' => 'soft_bounce',
            $type === 'bounce' => 'soft_bounce',
            $type === 'complaint' => 'complaint',
            $type === 'delivery' => 'delivered',
            $type === '' => 'unknown',
            default => $type,
        };
    }

    /**
     * The affected address: the bounced or complaining recipient when SES names one, otherwise
     * the first destination of the original mail.
     *
     * @param  array<string|int, mixed>  $payload
     */
    protected function sesRecipient(array $payload): string
    {
        return (string) ($payload['bounce']['bouncedRecipients'][0]['emailAddress']
            ?? $payload['complaint']['complainedRecipients'][0]['emailAddress']
            ?? $payload['mail']['destination'][0]
            ?? ($payload['email'] ?? ''));
    }

    /**
     * The recipient tracking token from the `X-Odden-Tracking-Token` header we send. SES lists
     * the original headers as `{name, value}` pairs in `mail.headers`, or `mail.tags` when the
     * message was sent with a tag.
     *
     * @param  array<string|int, mixed>  $payload
     */
    protected function sesTrackingToken(array $payload): string
    {
        foreach ((array) ($payload['mail']['headers'] ?? []) as $header) {
            if (is_array($header) && strcasecmp((string) ($header['name'] ?? ''), MarketingMessageMailable::TRACKING_TOKEN_HEADER) === 0) {
                return (string) ($header['value'] ?? '');
            }
        }

        $tag = $payload['mail']['tags']['odden_token'] ?? null;

        return (string) (is_array($tag) ? ($tag[0] ?? '') : ($tag ?? ''));
    }

    /**
     * Postmark event types: a spam complaint is a complaint, a permanent bounce is a hard bounce,
     * other bounces are soft, and records we don't act on keep their (lowercased) name.
     *
     * @param  array<string|int, mixed>  $payload
     */
    protected function postmarkEventType(array $payload): string
    {
        $record = strtolower((string) ($payload['RecordType'] ?? ''));
        $bounce = strtolower((string) ($payload['Type'] ?? ''));

        return match (true) {
            $record === 'spamcomplaint' => 'complaint',
            $record === 'bounce' && in_array($bounce, ['spamnotification', 'spamcomplaint'], true) => 'complaint',
            $record === 'bounce' && in_array($bounce, ['hardbounce', 'bademailaddress', 'manuallydeactivated'], true) => 'hard_bounce',
            $record === 'bounce' => 'soft_bounce',
            $record === 'delivery' => 'delivered',
            $record === 'subscriptionchange' => ($payload['SuppressSending'] ?? false) ? 'unsubscribed' : 'unknown',
            $record === '' => 'unknown',
            default => $record,
        };
    }

    /**
     * Normalize provider-specific webhook payload schemas into unified structure.
     *
     * @param  array<string|int, mixed>  $payload
     * @return array{email: string, event_type: string, tracking_token?: string|null, error_code?: string|null, error_message?: string|null}
     */
    protected function normalizePayload(string $provider, array $payload): array
    {
        return match (strtolower($provider)) {
            'mailgun' => [
                'email' => (string) ($payload['event-data']['recipient'] ?? ($payload['recipient'] ?? '')),
                'event_type' => $this->mailgunEventType($payload),
                'error_code' => (string) ($payload['event-data']['delivery-status']['code'] ?? null),
                'error_message' => (string) ($payload['event-data']['delivery-status']['message'] ?? null),
                'tracking_token' => (string) ($payload['event-data']['user-variables']['odden_token'] ?? null),
            ],
            'ses' => [
                'email' => $this->sesRecipient($payload),
                'event_type' => $this->sesEventType($payload),
                'error_code' => (string) ($payload['bounce']['bounceSubType'] ?? null),
                'error_message' => (string) ($payload['bounce']['bouncedRecipients'][0]['diagnosticCode'] ?? null),
                'tracking_token' => $this->sesTrackingToken($payload),
            ],
            'postmark' => [
                'email' => (string) ($payload['Recipient'] ?? ($payload['Email'] ?? '')),
                'event_type' => $this->postmarkEventType($payload),
                'error_code' => (string) ($payload['TypeCode'] ?? null),
                'error_message' => (string) ($payload['Details'] ?? null),
                'tracking_token' => (string) ($payload['Metadata']['odden_token'] ?? null),
            ],
            'sendgrid' => [
                'email' => (string) ($payload['email'] ?? ''),
                'event_type' => match (strtolower((string) ($payload['event'] ?? ''))) {
                    // "blocked" bounces are temporary refusals; the rest are permanent ("bounce" suppresses).
                    'bounce' => strtolower((string) ($payload['type'] ?? '')) === 'blocked' ? 'soft_bounce' : 'bounce',
                    // SendGrid declined to send it (address already suppressed, invalid, ...): record it, suppress nobody.
                    'dropped' => 'dropped',
                    'spamreport' => 'complaint',
                    'unsubscribe' => 'unsubscribed',
                    default => (string) (($payload['event'] ?? '') !== '' ? $payload['event'] : 'unknown'),
                },
                'error_code' => (string) ($payload['status'] ?? null),
                'error_message' => (string) ($payload['reason'] ?? null),
                'tracking_token' => (string) ($payload['odden_token'] ?? null),
            ],
            'resend' => [
                'email' => (string) (($payload['data']['to'][0] ?? null) ?? ($payload['email'] ?? '')),
                'event_type' => match (strtolower((string) ($payload['type'] ?? ''))) {
                    'email.bounced' => 'bounce',
                    'email.complained' => 'complaint',
                    'email.delivered' => 'delivered',
                    default => (string) ($payload['type'] ?? 'unknown'),
                },
                'error_code' => (string) ($payload['data']['bounce_type'] ?? null),
                'error_message' => (string) ($payload['data']['message'] ?? null),
                'tracking_token' => (string) ($payload['data']['tags']['odden_token'] ?? null),
            ],
            default => [
                'email' => (string) ($payload['email'] ?? ($payload['recipient'] ?? '')),
                'event_type' => (string) ($payload['event_type'] ?? ($payload['type'] ?? ($payload['event'] ?? 'unknown'))),
                'error_code' => (string) ($payload['error_code'] ?? ($payload['code'] ?? null)),
                'error_message' => (string) ($payload['error_message'] ?? ($payload['reason'] ?? null)),
                'tracking_token' => (string) ($payload['tracking_token'] ?? null),
            ],
        };
    }
}
