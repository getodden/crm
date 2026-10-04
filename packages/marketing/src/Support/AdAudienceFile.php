<?php

declare(strict_types=1);

namespace Odden\Marketing\Support;

use Generator;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Marketing\Enums\SubscriptionStatus;
use Odden\Marketing\Events\AdAudienceExported;
use Odden\Marketing\Models\AdAudienceSync;
use Odden\Marketing\Models\EmailSuppression;
use Odden\Marketing\Models\MarketingSubscription;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A CSV of hashed email addresses from an ad audience sync's list, in the form each ad platform's own "upload a list"
 * screen takes, so an audience can be created by hand without any API access to the platform.
 *
 *   google    header "Email"; lowercase hex SHA-256; the address is trimmed and lowercased, and dots before the @ are
 *             removed for gmail.com and googlemail.com (Google's rule for hashing it yourself)
 *   meta      header "email"; lowercase hex SHA-256 of the trimmed, lowercased address
 *   linkedin  header "email"; SHA-256 of the lowercased address with no whitespace, in the uppercase hex LinkedIn's
 *             documentation shows (it also needs at least 300 rows)
 *
 * Contacts who unsubscribed, bounced or are on the suppression list are left out, and so are addresses that hash to the
 * same value after normalising. The file is written as it is read, so a long list does not have to fit in memory.
 */
final class AdAudienceFile
{
    /** @var array<string, array{label: string, header: string, uppercase: bool}> */
    public const array FORMATS = [
        'google' => ['label' => 'Google Ads (Customer Match)', 'header' => 'Email', 'uppercase' => false],
        'meta' => ['label' => 'Meta Ads (Custom Audience)', 'header' => 'email', 'uppercase' => false],
        'linkedin' => ['label' => 'LinkedIn Ads (Matched Audiences)', 'header' => 'email', 'uppercase' => true],
    ];

    public int $included = 0;

    public int $suppressed = 0;

    public function __construct(private readonly AdAudienceSync $sync, private readonly string $format)
    {
        if (! array_key_exists($format, self::FORMATS)) {
            throw new InvalidArgumentException("Unknown audience file format [{$format}]. Use one of: ".implode(', ', array_keys(self::FORMATS)).'.');
        }
    }

    /**
     * The format for a sync's own platform, when it is one of the three (otherwise Meta's, the plainest).
     */
    public static function formatFor(AdAudienceSync $sync): string
    {
        return array_key_exists($sync->platform, self::FORMATS) ? $sync->platform : 'meta';
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return array_map(fn (array $format): string => $format['label'], self::FORMATS);
    }

    /**
     * The address as the platform expects it before it is hashed.
     */
    public static function normalize(string $email, string $format): string
    {
        $email = mb_strtolower(trim($email));

        if ($format === 'linkedin') {
            return (string) preg_replace('/\s+/', '', $email);
        }

        if ($format === 'google' && preg_match('/^(.+)@(gmail\.com|googlemail\.com)$/', $email, $parts) === 1) {
            return str_replace('.', '', $parts[1]).'@'.$parts[2];
        }

        return $email;
    }

    public static function hash(string $email, string $format): string
    {
        $hash = hash('sha256', self::normalize($email, $format));

        return (self::FORMATS[$format]['uppercase'] ?? false) ? strtoupper($hash) : $hash;
    }

    /**
     * Whether the list has anyone with an email address at all (before anyone is left out).
     */
    public function hasContacts(): bool
    {
        if ($this->sync->list === null) {
            return false;
        }

        return $this->contacts()->where(fn ($query) => $query->whereNotNull((new Contact)->getTable().'.email')->where((new Contact)->getTable().'.email', '!=', ''))->exists();
    }

    public function filename(): string
    {
        $list = Str::slug((string) ($this->sync->list->name ?? $this->sync->name), '-');

        return ($list !== '' ? $list : 'audience').'-'.$this->format.'-hashed-'.now()->format('Y-m-d').'.csv';
    }

    /**
     * The hashes, one per person, in contact order. $included and $suppressed are complete once it has been read through.
     *
     * @return Generator<int, string>
     */
    public function hashes(): Generator
    {
        $this->included = 0;
        $this->suppressed = 0;
        $suppressed = $this->suppressedAddresses();
        $seen = [];
        $table = (new Contact)->getTable();

        foreach ($this->contacts()->select("{$table}.id", "{$table}.email")->orderBy("{$table}.id")->lazyById(1000, "{$table}.id", 'id') as $contact) {
            $email = mb_strtolower(trim((string) $contact->email));

            if ($email === '') {
                continue;
            }

            if (isset($suppressed[$email])) {
                $this->suppressed++;

                continue;
            }

            $hash = self::hash($email, $this->format);
            // The first 64 bits tell hashes apart well enough for this, and keep the memory of a long list small.
            $key = substr($hash, 0, 16);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $this->included++;

            yield $hash;
        }
    }

    /**
     * The download: the header, then a hash per line. Tells the host (AdAudienceExported) once it has been sent.
     */
    public function stream(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            echo self::FORMATS[$this->format]['header']."\n";

            foreach ($this->hashes() as $position => $hash) {
                echo $hash."\n";

                // Send what has been written every so often, so a long list is not held back until the end.
                if ($position % 5000 === 4999) {
                    flush();
                }
            }

            AdAudienceExported::dispatch($this->sync, $this->format, $this->included, $this->suppressed);
        }, $this->filename(), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return BelongsToMany<Contact, CrmList>
     */
    private function contacts(): BelongsToMany
    {
        $list = $this->sync->list ?? throw new InvalidArgumentException('This audience has no source list.');

        // An active (rule-based) list is refreshed first, as Sync Now does.
        $list->syncActiveMembers();

        return $list->contacts();
    }

    /**
     * Every address that must not be sent to an ad platform, as keys for a quick lookup.
     *
     * @return array<string, true>
     */
    private function suppressedAddresses(): array
    {
        $unsubscribed = MarketingSubscription::query()
            ->whereIn('status', [SubscriptionStatus::Unsubscribed->value, SubscriptionStatus::Bounced->value])
            ->pluck('email');

        $addresses = [];

        foreach ($unsubscribed->merge(EmailSuppression::query()->pluck('email')) as $email) {
            $addresses[mb_strtolower(trim((string) $email))] = true;
        }

        return $addresses;
    }
}
