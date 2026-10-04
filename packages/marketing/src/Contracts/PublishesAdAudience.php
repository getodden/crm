<?php

declare(strict_types=1);

namespace Odden\Marketing\Contracts;

use Odden\Marketing\Models\AdAudienceSync;

/**
 * Sends a CRM list to an advertising platform as an audience, for the "Sync Now" action on an ad audience sync.
 *
 * The built-in implementation (SyncAdAudienceAction) only prepares the audience: it computes the SHA-256 hashes the
 * platforms expect and records how many contacts there are. It does not contact Google, LinkedIn or Meta. An application
 * or add-on can rebind this contract to an implementation that uploads the audience, as long as it returns this shape.
 *
 *     $this->app->bind(PublishesAdAudience::class, MyAudiencePublisher::class);
 */
interface PublishesAdAudience
{
    /**
     * @return array{
     *     platform: string,
     *     records_synced: int,
     *     audience_id: string|null,
     *     message?: string,
     *     hashed_emails?: list<string>,
     *     hashed_domains?: list<string>
     * }  "message" is shown to the person who pressed the button, in place of the default wording.
     */
    public function execute(AdAudienceSync $sync): array;
}
