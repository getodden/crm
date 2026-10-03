<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\PageView;
use Odden\Marketing\Models\VisitorSession;
use Odden\Marketing\Support\VisitorToken;

class RecordWebVisitAction
{
    /**
     * High-intent URL path prefixes that trigger automatic lead scoring points.
     *
     * @var array<string, int>
     */
    protected const HIGH_INTENT_PATHS = [
        '/pricing' => 20,
        '/demo' => 15,
        '/enterprise' => 25,
        '/quote' => 20,
    ];

    /**
     * Record an inbound web visit, managing the visitor session, logging the pageview,
     * and scoring high-intent browsing activity.
     *
     * @param  array{
     *     visitor_token?: string|null,
     *     contact_id?: int|null,
     *     url: string,
     *     path?: string|null,
     *     title?: string|null,
     *     ip_address?: string|null,
     *     user_agent?: string|null,
     *     referer?: string|null,
     *     utm_source?: string|null,
     *     utm_medium?: string|null,
     *     utm_campaign?: string|null,
     *     duration_seconds?: int|null
     * }  $data
     * @return array{session: VisitorSession, page_view: PageView}
     */
    public function execute(array $data): array
    {
        // Malformed tokens (see VisitorToken::PATTERN) are replaced by a fresh one.
        $visitorToken = VisitorToken::isValid($data['visitor_token'] ?? null)
            ? (string) $data['visitor_token']
            : VisitorToken::generate();
        $url = $data['url'];
        $path = $data['path'] ?? (parse_url($url, PHP_URL_PATH) ?: '/');
        $contactId = $data['contact_id'] ?? null;

        /** @var VisitorSession $session */
        $session = VisitorSession::query()->firstOrCreate(
            ['visitor_token' => $visitorToken],
            [
                'contact_id' => $contactId,
                'ip_address' => $data['ip_address'] ?? null,
                'user_agent' => $data['user_agent'] ?? null,
                'referer' => $data['referer'] ?? null,
                'utm_source' => $data['utm_source'] ?? null,
                'utm_medium' => $data['utm_medium'] ?? null,
                'utm_campaign' => $data['utm_campaign'] ?? null,
                'first_seen_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        // Update last seen and link contact if newly known
        $sessionUpdates = ['last_seen_at' => now()];
        if ($contactId !== null && $session->contact_id === null) {
            $sessionUpdates['contact_id'] = $contactId;
        }
        $session->update($sessionUpdates);

        // Record page view
        /** @var PageView $pageView */
        $pageView = PageView::create([
            'session_id' => $session->id,
            'contact_id' => $session->contact_id,
            'url' => $url,
            'path' => $path,
            'title' => $data['title'] ?? null,
            'duration_seconds' => $data['duration_seconds'] ?? null,
            'created_at' => now(),
        ]);

        // Evaluate High-Intent Page Lead Scoring
        $resolvedContactId = $session->contact_id ?? $contactId;
        if ($resolvedContactId !== null) {
            /** @var Contact|null $contact */
            $contact = Contact::query()->find($resolvedContactId);
            if ($contact !== null) {
                foreach (self::HIGH_INTENT_PATHS as $intentPath => $scoreBonus) {
                    if (str_starts_with(strtolower($path), $intentPath)) {
                        app(ApplyLeadScoringEventAction::class)->execute(
                            contact: $contact,
                            eventType: LeadScoringEventType::PropertyMatch,
                            description: "High Intent Web Visit: {$path} (+{$scoreBonus} pts)",
                            context: ['path' => $path, 'bonus' => $scoreBonus],
                            points: $scoreBonus,
                        );
                        break;
                    }
                }
            }
        }

        return [
            'session' => $session,
            'page_view' => $pageView,
        ];
    }
}
