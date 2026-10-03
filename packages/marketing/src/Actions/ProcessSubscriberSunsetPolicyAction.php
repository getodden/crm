<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\MarketingSubscription;

class ProcessSubscriberSunsetPolicyAction
{
    /**
     * Identify dormant email subscribers who have received multiple marketing broadcasts
     * but have zero opens or clicks over the inactivity window, and either flag them or
     * automatically suppress them to safeguard domain sender reputation.
     *
     * @return array{
     *     dormant_evaluated_count: int,
     *     dormant_detected_count: int,
     *     auto_suppressed_count: int,
     *     contact_ids: list<int>
     * }
     */
    public function execute(
        int $inactivityDays = 90,
        int $minSendsReceived = 3,
        bool $autoSuppress = false
    ): array {
        $detector = new DetectUnengagedContactsAction;

        // Contacts who are being mailed but haven't engaged inside the window.
        /** @var Collection<int, Contact> $candidates */
        $candidates = $detector->candidates($inactivityDays, $minSendsReceived)->get();

        $dormantDetected = [];
        $suppressedCount = 0;

        foreach ($candidates as $contact) {
            $email = mb_strtolower(trim($contact->email));

            // Skip if already unsubscribed or suppressed
            if ($detector->isSuppressed($contact)) {
                continue;
            }

            // Contact is confirmed dormant
            $dormantDetected[] = $contact->id;

            DB::transaction(function () use ($contact, $email, $autoSuppress, $inactivityDays, &$suppressedCount): void {
                $props = $contact->properties ?? [];

                if ($autoSuppress) {
                    MarketingSubscription::unsubscribe($email, $contact->id);

                    $props['sunset_suppressed'] = true;
                    $props['sunset_suppressed_at'] = now()->toIso8601String();
                    $suppressedCount++;

                    $contact->logTask(
                        title: 'Subscriber Sunset Protection Applied',
                        dueAt: now(),
                        body: "Contact automatically suppressed after {$inactivityDays} days of dormancy without email opens or clicks."
                    );
                } else {
                    $props['is_sunset_dormant'] = true;
                    $props['sunset_dormant_detected_at'] = now()->toIso8601String();
                }

                $contact->updateQuietly(['properties' => $props]);
            });
        }

        return [
            'dormant_evaluated_count' => $candidates->count(),
            'dormant_detected_count' => count($dormantDetected),
            'auto_suppressed_count' => $suppressedCount,
            'contact_ids' => $dormantDetected,
        ];
    }
}
