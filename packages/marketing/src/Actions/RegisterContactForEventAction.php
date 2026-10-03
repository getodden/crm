<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Facades\DB;
use Odden\Core\Models\Contact;
use Odden\Marketing\Enums\LeadScoringEventType;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingEventRegistration;

class RegisterContactForEventAction
{
    public function __construct(
        public ApplyLeadScoringEventAction $scoringAction,
    ) {}

    /**
     * Register a contact for an event/webinar, record UTM attribution, and update counters.
     *
     * @param  array{utm_source?: string|null, utm_medium?: string|null, utm_campaign?: string|null}  $utm
     */
    public function execute(
        MarketingEvent $event,
        Contact $contact,
        array $utm = []
    ): MarketingEventRegistration {
        return DB::transaction(function () use ($event, $contact, $utm): MarketingEventRegistration {
            /** @var MarketingEventRegistration|null $existing */
            $existing = MarketingEventRegistration::query()
                ->where('event_id', $event->id)
                ->where('contact_id', $contact->id)
                ->first();

            $isNew = $existing === null;

            /** @var MarketingEventRegistration $registration */
            $registration = MarketingEventRegistration::updateOrCreate(
                [
                    'event_id' => $event->id,
                    'contact_id' => $contact->id,
                ],
                [
                    // Registering again must not undo attendance that was already recorded.
                    'status' => $existing?->status === 'attended' ? 'attended' : 'registered',
                    'registered_at' => $existing?->status === 'attended' ? $existing->registered_at : now(),
                    'utm_source' => $utm['utm_source'] ?? $existing?->utm_source,
                    'utm_medium' => $utm['utm_medium'] ?? $existing?->utm_medium,
                    'utm_campaign' => $utm['utm_campaign'] ?? $existing?->utm_campaign,
                ]
            );

            if ($isNew) {
                $event->increment('registrations_count');

                // Log task on contact activity timeline
                $contact->logTask(
                    title: "Registered for Event: {$event->title}",
                    dueAt: now(),
                    body: "Registered for {$event->event_type}".($event->starts_at !== null ? " scheduled on {$event->starts_at->toDayDateTimeString()}." : '.')
                );

                // Award registration lead scoring bonus (+10 pts)
                $this->scoringAction->execute(
                    contact: $contact,
                    eventType: LeadScoringEventType::PropertyMatch,
                    description: "Registered for Event: {$event->title} (+10 pts)",
                    points: 10,
                );
            }

            return $registration;
        });
    }
}
