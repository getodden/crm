<?php

declare(strict_types=1);

namespace Odden\Marketing\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Odden\Core\Models\Contact;

/**
 * Subscription preferences stored on the contact's marketing columns, which this package's
 * migrations add (`marketing_topics`, `marketing_verification_token`).
 */
class ContactPreferences
{
    /**
     * Whether the contact is subscribed to a marketing topic. A contact who never chose
     * (`marketing_topics` is null) is subscribed to everything.
     */
    public static function isSubscribedToTopic(Contact $contact, string $topic): bool
    {
        if ($contact->marketing_topics === null) {
            return true;
        }

        return in_array($topic, (array) $contact->marketing_topics, true);
    }

    /**
     * The contact's preference center URL. The verification token is generated the first time
     * and saved quietly, since it is a secret that shouldn't land in the property history.
     */
    public static function preferenceCenterUrl(Contact $contact): string
    {
        $token = $contact->marketing_verification_token ?: Str::random(40);
        if ($contact->marketing_verification_token === null) {
            $contact->updateQuietly(['marketing_verification_token' => $token]);
        }

        return Route::has('odden.marketing.preferences.show')
            ? route('odden.marketing.preferences.show', $token)
            : url('/marketing/preferences/'.$token);
    }
}
