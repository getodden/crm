---
title: Ad audiences
description: Turn a CRM list into an audience on Google Ads, Meta or LinkedIn by downloading a file of hashed email addresses, and what Sync Now does.
---

An **ad audience sync** (`Odden\Marketing\Models\AdAudienceSync`) ties a [list](../core/lists.md) to an advertising platform: `platform` is `google`, `meta` or `linkedin`, `list_id` is the source list, and `audience_id` is the audience's id on the platform if you want to note it. In the panel it is the **Ad Audience Sync Bridges** resource.

## Sync Now

**Sync Now** runs `PublishesAdAudience`, whose built-in implementation is `SyncAdAudienceAction`. It refreshes the list if it is an active list, computes a SHA-256 hash of each member's email address (and of each address's domain), and records the count and the time. **It does not contact Google, LinkedIn or Meta.** To upload an audience without connecting anything, download the file below. To upload it automatically, [bind another implementation](../filament/customizing.md#swapping-a-built-in-behaviour) of `Odden\Marketing\Contracts\PublishesAdAudience`.

## Download a hashed file

The **Download hashed file** row action gives you a CSV to upload by hand, with a header and one hash per line. Choose the platform it is for (it starts on the sync's own platform):

| Platform | Header | Hash | Normalised before hashing |
| --- | --- | --- | --- |
| Google Ads (Customer Match) | `Email` | SHA-256, lowercase hex | trimmed and lowercased; dots before the `@` removed for `gmail.com` and `googlemail.com` |
| Meta Ads (Custom Audience) | `email` | SHA-256, lowercase hex | trimmed and lowercased |
| LinkedIn Ads (Matched Audiences) | `email` | SHA-256, uppercase hex | lowercased, with no whitespace |

These follow each platform's own help pages as of October 2026. If a platform rejects a file, check its current requirements first. LinkedIn needs at least 300 rows, and Google and Meta also have minimum list sizes before an audience can be used.

Who is in the file:

- Contacts who **unsubscribed or bounced** (`MarketingSubscription`), or whose address is on the **suppression list** (`EmailSuppression`), are left out.
- Contacts with **no email address** are skipped.
- Addresses that give the **same hash after normalising** appear once. Google treats `john.smith@gmail.com` and `johnsmith@gmail.com` as one person, so the Google file has one line for the two.
- An active (rule-based) list is refreshed first, as Sync Now does.

The file is written as it is read, so a long list does not have to fit in memory. An empty list gives a notice and no file.

Hashing an address does not make it anonymous: privacy law generally still treats a hashed email address as personal data. Only upload people you have the right to use for advertising. An unsubscribe from email is not the same as consent to advertising, which is why the file leaves unsubscribed contacts out, but that does not replace asking.

### Keeping a record of downloads

After the file has been sent, `Odden\Marketing\Events\AdAudienceExported` is dispatched with the sync, the platform format, how many addresses were included, and how many contacts were left out as suppressed. A host application can listen for it to record who took the file.

```php
use Odden\Marketing\Events\AdAudienceExported;

Event::listen(AdAudienceExported::class, function (AdAudienceExported $event): void {
    Log::info('Ad audience file downloaded', [
        'sync' => $event->sync->name,
        'format' => $event->format,
        'included' => $event->included,
        'suppressed' => $event->suppressed,
    ]);
});
```

### Building the file in code

`Odden\Marketing\Support\AdAudienceFile` builds the same file:

```php
use Odden\Marketing\Support\AdAudienceFile;

$file = new AdAudienceFile($sync, 'google');

foreach ($file->hashes() as $hash) {
    // one hash per person; $file->included and $file->suppressed are complete once it has been read through
}

return $file->stream(); // a CSV download with a name like buyers-google-hashed-2026-10-04.csv
```
