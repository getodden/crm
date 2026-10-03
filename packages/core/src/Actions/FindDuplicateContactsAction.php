<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Collection;
use Odden\Core\Models\Contact;

class FindDuplicateContactsAction
{
    /**
     * Find groups of duplicate contacts based on matching email, phone, or full name.
     *
     * Emails match case-insensitively, phone numbers match on their digits only (so "(555) 010-1234"
     * and "555.010.1234" are the same number), and names match on first plus last name,
     * case-insensitively. A group of contacts that was already reported under an earlier field
     * (email, then phone, then name) is not reported again.
     *
     * @return array<int, array{
     *     match_field: string,
     *     match_value: string,
     *     contacts: Collection<int, Contact>
     * }>
     */
    public function execute(): array
    {
        /** @var array<string, array<string, list<int>>> $buckets */
        $buckets = ['email' => [], 'phone' => [], 'name' => []];

        Contact::query()
            ->select(['id', 'email', 'phone', 'first_name', 'last_name'])
            ->orderBy('id')
            ->chunkById(1000, function (Collection $contacts) use (&$buckets): void {
                foreach ($contacts as $contact) {
                    $email = mb_strtolower(trim((string) $contact->email));
                    if ($email !== '') {
                        $buckets['email'][$email][] = $contact->id;
                    }

                    $phone = (string) preg_replace('/\D+/', '', (string) $contact->phone);
                    if ($phone !== '') {
                        $buckets['phone'][$phone][] = $contact->id;
                    }

                    $first = mb_strtolower(trim((string) $contact->first_name));
                    $last = mb_strtolower(trim((string) $contact->last_name));
                    if ($first !== '' && $last !== '') {
                        $buckets['name']["{$first} {$last}"][] = $contact->id;
                    }
                }
            });

        $duplicates = [];
        $reported = [];

        foreach ($buckets as $field => $groups) {
            foreach ($groups as $value => $ids) {
                if (count($ids) < 2) {
                    continue;
                }

                sort($ids);
                $signature = implode(',', $ids);
                if (isset($reported[$signature])) {
                    continue;
                }
                $reported[$signature] = true;

                $duplicates[] = [
                    'match_field' => $field,
                    'match_value' => (string) $value,
                    'contacts' => Contact::query()->whereIn('id', $ids)->orderBy('id')->get(),
                ];
            }
        }

        return $duplicates;
    }
}
