<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Odden\Core\Models\Contact;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\MarketingSubscription;

class DetectUnengagedContactsAction
{
    /**
     * Flag contacts who are being mailed but never engage.
     *
     * A contact is unengaged when they have received at least $minSends campaign emails, were first
     * mailed at least $daysInactive days ago, are still being mailed (a send inside that window),
     * and have not opened or clicked anything inside that window. Contacts we stopped mailing are
     * not unengaged, they're just quiet, and suppressed or unsubscribed contacts are always left out.
     *
     * @return Collection<int, Contact>
     */
    public function execute(int $daysInactive = 90, int $minSends = 3): Collection
    {
        /** @var Collection<int, Contact> $contacts */
        $contacts = $this->candidates($daysInactive, $minSends)
            ->where('is_unengaged', false)
            ->get()
            ->reject(fn (Contact $contact): bool => $this->isSuppressed($contact))
            ->values();

        foreach ($contacts as $contact) {
            $contact->update([
                'is_unengaged' => true,
                'unengaged_since' => now(),
                'sunset_stage' => 'flagged',
            ]);
        }

        return $contacts;
    }

    /**
     * Contacts matching the unengaged definition, without flagging them or checking suppression.
     *
     * @return Builder<Contact>
     */
    public function candidates(int $daysInactive = 90, int $minSends = 3): Builder
    {
        $cutoff = now()->subDays($daysInactive);

        $contactIds = CampaignRecipient::query()
            ->select('contact_id')
            ->whereNotNull('contact_id')
            ->whereNotNull('sent_at')
            ->groupBy('contact_id')
            ->havingRaw('count(*) >= ?', [max(1, $minSends)])
            ->havingRaw('min(sent_at) <= ?', [$cutoff])
            ->havingRaw('max(sent_at) >= ?', [$cutoff])
            ->havingRaw('(max(opened_at) is null or max(opened_at) < ?)', [$cutoff])
            ->havingRaw('(max(clicked_at) is null or max(clicked_at) < ?)', [$cutoff]);

        return Contact::query()->whereIn((new Contact)->getKeyName(), $contactIds);
    }

    public function isSuppressed(Contact $contact): bool
    {
        $email = mb_strtolower(trim((string) $contact->email));

        return $contact->sunset_stage === 'suppressed'
            || $email === ''
            || MarketingSubscription::isSuppressed($email);
    }
}
