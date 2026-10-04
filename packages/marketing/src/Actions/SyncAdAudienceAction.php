<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Illuminate\Database\Eloquent\Collection;
use Odden\Core\Models\Contact;
use Odden\Marketing\Contracts\PublishesAdAudience;
use Odden\Marketing\Models\AdAudienceSync;

class SyncAdAudienceAction implements PublishesAdAudience
{
    /**
     * Compute SHA-256 privacy hashes for CRM list members and sync with Google, LinkedIn, or Meta Ads.
     *
     * @return array{
     *     platform: string,
     *     records_synced: int,
     *     audience_id: string|null,
     *     message?: string,
     *     hashed_emails: list<string>,
     *     hashed_domains: list<string>
     * }  The PublishesAdAudience shape; this implementation never sets "message", and always returns the hashes.
     */
    public function execute(AdAudienceSync $sync): array
    {
        $list = $sync->list;

        if ($list !== null) {
            $list->syncActiveMembers();
            /** @var Collection<int, Contact> $contacts */
            $contacts = $list->contacts()->get();
        } else {
            $contacts = new Collection;
        }

        $hashedEmails = [];
        $hashedDomains = [];

        foreach ($contacts as $contact) {
            $rawEmail = strtolower(trim($contact->email));
            if (! empty($rawEmail)) {
                $hashedEmails[] = hash('sha256', $rawEmail);

                // Extract and hash domain for B2B Account Retargeting
                if (str_contains($rawEmail, '@')) {
                    $domain = explode('@', $rawEmail)[1] ?? '';
                    if (! empty($domain)) {
                        $hashedDomains[] = hash('sha256', strtolower(trim($domain)));
                    }
                }
            }
        }

        $hashedEmails = array_values(array_unique($hashedEmails));
        $hashedDomains = array_values(array_unique($hashedDomains));
        $totalRecords = count($hashedEmails);

        $sync->update([
            'records_count' => $totalRecords,
            'last_synced_at' => now(),
        ]);

        return [
            'platform' => $sync->platform,
            'records_synced' => $totalRecords,
            'audience_id' => $sync->audience_id,
            'hashed_emails' => $hashedEmails,
            'hashed_domains' => $hashedDomains,
        ];
    }
}
