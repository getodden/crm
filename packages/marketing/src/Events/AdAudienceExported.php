<?php

declare(strict_types=1);

namespace Odden\Marketing\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Odden\Marketing\Models\AdAudienceSync;

/**
 * Dispatched when a hashed audience file has been sent to the person who asked for it (the "Download hashed file"
 * action on an ad audience sync). The file holds a hash of every contact's email address, so a host application can
 * listen for this to keep a record of who took it, and of how many people it covered.
 */
class AdAudienceExported
{
    use Dispatchable;

    /**
     * @param  string  $format  The platform the file was prepared for: google, meta or linkedin.
     * @param  int  $included  Addresses in the file.
     * @param  int  $suppressed  Contacts left out because they unsubscribed, bounced or are on the suppression list.
     */
    public function __construct(
        public AdAudienceSync $sync,
        public string $format,
        public int $included,
        public int $suppressed,
    ) {}
}
