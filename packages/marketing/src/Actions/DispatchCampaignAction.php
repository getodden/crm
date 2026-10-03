<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Support\Collection;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Marketing\Enums\CampaignStatus;
use Odden\Marketing\Enums\RecipientStatus;
use Odden\Marketing\Exceptions\CampaignHasNoAudienceException;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;

class DispatchCampaignAction
{
    public function __construct(
        protected ?DeliverCampaignMessageAction $delivery = null,
    ) {}

    /**
     * Dispatch an email marketing campaign to its targeted list audience or A/B test sample.
     *
     * Each eligible recipient's message is queued through DeliverCampaignMessageAction.
     * Dispatch is idempotent: recipients are unique per campaign and contact, and a
     * recipient that was already sent is not sent again, so a failed run can be re-run.
     *
     * @param  Collection<int, Contact>|null  $explicitContacts  Send to these contacts instead of the campaign's list.
     * @return array{total_recipients: int, delivered_count: int, suppressed_count: int}
     *
     * @throws CampaignHasNoAudienceException when the campaign has no list and no contacts are passed.
     */
    public function execute(Campaign $campaign, ?Collection $explicitContacts = null): array
    {
        // Resolve audience list (static or dynamic active list). Never fall back to every contact.
        $audienceList = $campaign->crmList ?? $campaign->list;
        if ($explicitContacts === null && $audienceList === null) {
            throw CampaignHasNoAudienceException::for($campaign);
        }

        $campaign->update(['status' => CampaignStatus::Sending]);

        if ($explicitContacts !== null) {
            $contacts = $explicitContacts;
        } else {
            /** @var CrmList $audienceList */
            $audienceList->syncActiveMembers();
            /** @var Collection<int, Contact> $contacts */
            $contacts = $audienceList->contacts()->get();
        }

        $contacts = $contacts->unique(fn (Contact $contact): int => (int) $contact->getKey())->values();

        $delivery = $this->delivery ?? app(DeliverCampaignMessageAction::class);
        $deliveredCount = 0;
        $suppressedCount = 0;

        $useTimezoneSending = $campaign->send_in_recipient_timezone || $campaign->send_by_timezone || $campaign->use_sto;

        // A/B Split Testing Mode
        if ($campaign->is_ab_test) {
            // Same safeguards as a standard broadcast: suppression, topic and fatigue protection.
            $eligible = $contacts
                ->filter(fn (Contact $c): bool => $delivery->canReceive($campaign, (string) $c->email, $c))
                ->values();

            $totalRecipients = $eligible->count();
            $suppressedCount = $contacts->count() - $totalRecipients;

            $samplePct = $campaign->ab_test_sample_percentage ?: 20;
            $sampleTotal = min($totalRecipients, max(2, (int) round($totalRecipients * ($samplePct / 100))));
            if ($sampleTotal % 2 !== 0 && $sampleTotal < $totalRecipients) {
                $sampleTotal++;
            }

            $halfSample = (int) ($sampleTotal / 2);
            $variantAContacts = $eligible->slice(0, $halfSample);
            $variantBContacts = $eligible->slice($halfSample, $halfSample);
            $remainingContacts = $eligible->slice($sampleTotal);

            foreach (['A' => $variantAContacts, 'B' => $variantBContacts] as $variant => $variantContacts) {
                foreach ($variantContacts as $contact) {
                    // Honor the recipient's local send time: hold the test send, with its variant,
                    // until marketing:dispatch-scheduled releases it.
                    if ($useTimezoneSending) {
                        $targetTime = $campaign->calculateScheduledTimeForContact($contact);
                        if ($targetTime->isFuture() && now()->diffInMinutes($targetTime) > 5) {
                            $this->recipientFor($campaign, $contact, ['variant' => (string) $variant, 'scheduled_send_at' => $targetTime]);

                            continue;
                        }
                    }

                    if ($this->deliver($delivery, $campaign, $this->recipientFor($campaign, $contact), (string) $variant)) {
                        $deliveredCount++;
                    }
                }
            }

            // Stage remaining recipients pending winner evaluation
            foreach ($remainingContacts as $contact) {
                $this->recipientFor($campaign, $contact);
            }

            $campaign->update([
                'status' => CampaignStatus::Sending,
                'sent_at' => $campaign->sent_at ?? now(),
                'total_recipients' => $totalRecipients,
                'delivered_count' => $this->sentCount($campaign),
            ]);

            return [
                'total_recipients' => $totalRecipients,
                'delivered_count' => $deliveredCount,
                'suppressed_count' => $suppressedCount,
            ];
        }

        // Standard Full Broadcast Mode
        $totalRecipients = $contacts->count();

        foreach ($contacts as $contact) {
            if (! $delivery->canReceive($campaign, (string) $contact->email, $contact)) {
                $suppressedCount++;

                continue;
            }

            if ($useTimezoneSending) {
                $targetTime = $campaign->calculateScheduledTimeForContact($contact);
                if ($targetTime->isFuture() && now()->diffInMinutes($targetTime) > 5) {
                    $this->recipientFor($campaign, $contact, ['scheduled_send_at' => $targetTime]);

                    continue;
                }
            }

            if ($this->deliver($delivery, $campaign, $this->recipientFor($campaign, $contact), null)) {
                $deliveredCount++;
            }
        }

        $hasPending = $campaign->recipients()->where('status', RecipientStatus::Pending->value)->exists();

        $campaign->update([
            'status' => $hasPending ? CampaignStatus::Sending : CampaignStatus::Sent,
            'sent_at' => $campaign->sent_at ?? now(),
            'total_recipients' => $totalRecipients,
            'delivered_count' => $this->sentCount($campaign),
        ]);

        return [
            'total_recipients' => $totalRecipients,
            'delivered_count' => $deliveredCount,
            'suppressed_count' => $suppressedCount,
        ];
    }

    /**
     * The campaign's recipient row for a contact, created (as pending) only once.
     *
     * firstOrCreate() looks the row up first (most re-dispatches find it) and only then inserts;
     * on Laravel 12+ that insert goes through createOrFirst(), so when a concurrent dispatch wins
     * the race on the (campaign_id, contact_id) unique index, the duplicate-key error is caught
     * and the other dispatch's row is returned instead of throwing.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function recipientFor(Campaign $campaign, Contact $contact, array $attributes = []): CampaignRecipient
    {
        /** @var CampaignRecipient $recipient */
        $recipient = $campaign->recipients()->firstOrCreate(
            ['contact_id' => $contact->id],
            [
                'email' => mb_strtolower(trim((string) $contact->email)),
                'status' => RecipientStatus::Pending,
                'variant' => null,
                ...$attributes,
            ],
        );

        $recipient->setRelation('contact', $contact);

        return $recipient;
    }

    /**
     * Queue one recipient's message. Eligibility was checked just before, so it is not re-checked.
     */
    protected function deliver(DeliverCampaignMessageAction $delivery, Campaign $campaign, CampaignRecipient $recipient, ?string $variant): bool
    {
        return $delivery->execute($campaign, $recipient, $variant, checkEligibility: false) === DeliverCampaignMessageAction::QUEUED;
    }

    protected function sentCount(Campaign $campaign): int
    {
        return $campaign->recipients()->whereNotNull('sent_at')->count();
    }
}
