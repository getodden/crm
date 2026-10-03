<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Support\UserModel;
use Odden\MailBuilder\MailBuilder;
use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;
use Odden\Marketing\Models\MarketingEvent;
use Odden\Marketing\Models\MarketingTemplate;
use Odden\Marketing\Support\ContactPreferences;

class CompileCampaignMessageAction
{
    /**
     * Compile HTML email body with merge tags, tracking pixel, and click wrappers.
     */
    public function execute(Campaign $campaign, CampaignRecipient $recipient): string
    {
        $isVariantB = $recipient->variant === 'B';
        $template = $this->templateFor($campaign, $recipient);
        $subject = $this->subjectFor($campaign, $recipient);

        /** @var Contact|null $contact */
        $contact = $recipient->contact ?? ($recipient->contact_id !== null ? Contact::find($recipient->contact_id) : null);

        /** @var Company|null $company */
        $company = $contact !== null ? $contact->companies()->first() : null;

        $recipientContext = [
            'contact' => $contact !== null ? $contact->toArray() : ['email' => $recipient->email],
            'company' => $company !== null ? $company->toArray() : [],
        ];

        $rawHtml = '<p>{{content}}</p>';
        if ($template !== null) {
            $slotsToUse = ($isVariantB && ! empty($template->slots_variant_b)) ? $template->slots_variant_b : $template->slots;
            if (! empty($slotsToUse) && class_exists(MailBuilder::class)) {
                $rawHtml = MailBuilder::compile($slotsToUse, [
                    'subject' => $subject,
                    'preview_text' => $isVariantB ? $template->preview_text_variant_b : $template->preview_text,
                    'theme' => $template->theme ?? [],
                    'context' => $recipientContext,
                ]);
            } else {
                $rawHtml = ($template->hasAbTest() && $campaign->variantBTemplate === null)
                    ? $template->getVariantHtml($recipient->variant ?? 'A')
                    : $template->body_html;
            }
        }

        $unsubscribeUrl = $recipient->getUnsubscribeUrl();
        $trackingPixelUrl = $recipient->getTrackingPixelUrl();

        // 1. Merge tags: every tag in the mail builder's registry, with its filters and conditionals.
        // Values are HTML-escaped: contact and company fields are untrusted input.
        $html = MailBuilder::interpolate($rawHtml, $this->mergeContext(
            contact: $contact,
            company: $company,
            email: $recipient->email,
            unsubscribeUrl: $unsubscribeUrl,
            campaign: $campaign,
            rawHtml: $rawHtml,
        ));

        // Evaluate smart dynamic content blocks
        $html = app(EvaluateSmartContentBlocksAction::class)->execute($html, $contact);

        // 2. Append UTM tracking parameters
        $html = app(AppendUtmParametersAction::class)->appendHtmlLinks($html, $campaign, $recipient->variant);

        // 3. Wrap links for click tracking (excluding mailto:, tel:, and unsubscribe).
        // The href attribute is HTML: decode it to the real URL before signing it, and
        // escape the tracking URL when writing it back, so "&" is encoded exactly once.
        $html = (string) preg_replace_callback(
            '/(<a\s+(?:[^>]*?\s+)?href=)(["\'])(.*?)\2/i',
            function (array $matches) use ($recipient, $unsubscribeUrl): string {
                $originalUrl = html_entity_decode($matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (
                    str_starts_with($originalUrl, 'mailto:') ||
                    str_starts_with($originalUrl, 'tel:') ||
                    str_starts_with($originalUrl, '#') ||
                    $originalUrl === $unsubscribeUrl ||
                    str_contains($originalUrl, CampaignRecipient::unsubscribePathPrefix())
                ) {
                    return $matches[0];
                }

                $trackingUrl = $recipient->getClickRedirectUrl($originalUrl);

                return $matches[1].$matches[2].htmlspecialchars($trackingUrl, ENT_QUOTES, 'UTF-8').$matches[2];
            },
            $html
        );

        // 3. Inject tracking pixel before </body> or append to end
        $pixelTag = '<img src="'.htmlspecialchars($trackingPixelUrl).'" width="1" height="1" alt="" style="display:none;width:1px;height:1px;border:0;" />';
        if (str_contains($html, '</body>')) {
            $html = str_replace('</body>', $pixelTag.'</body>', $html);
        } else {
            $html .= $pixelTag;
        }

        return $html;
    }

    /**
     * The template a recipient receives: the variant B template for variant B recipients when one is set.
     */
    public function templateFor(Campaign $campaign, CampaignRecipient $recipient): ?MarketingTemplate
    {
        return ($recipient->variant === 'B' && $campaign->variantBTemplate !== null)
            ? $campaign->variantBTemplate
            : $campaign->template;
    }

    /**
     * The subject line a recipient receives, honoring the A/B variant.
     */
    public function subjectFor(Campaign $campaign, CampaignRecipient $recipient): string
    {
        if ($recipient->variant !== 'B') {
            return $campaign->subject;
        }

        if (! empty($campaign->variant_b_subject)) {
            return $campaign->variant_b_subject;
        }

        $template = $this->templateFor($campaign, $recipient);

        return $template !== null ? $template->getVariantSubject('B') : $campaign->subject;
    }

    /**
     * Plain-text alternative of a compiled HTML message: links become "label (url)".
     */
    public function plainText(string $html): string
    {
        $html = (string) preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $html);
        $text = MailBuilder::plainText($html);

        // Trim each line and collapse the blank lines that block markup leaves behind.
        $text = implode("\n", array_map(trim(...), explode("\n", $text)));

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /**
     * Compile raw HTML template for a given contact (used by drip workflows).
     */
    public function compileForContact(string $rawHtml, Contact $contact, bool $escape = true): string
    {
        /** @var Company|null $company */
        $company = $contact->companies()->first();

        // Values are HTML-escaped for HTML bodies: contact and company fields are untrusted input.
        // Plain-text messages such as SMS pass $escape = false.
        $html = MailBuilder::interpolate($rawHtml, $this->mergeContext(
            contact: $contact,
            company: $company,
            email: (string) $contact->email,
            unsubscribeUrl: ContactPreferences::preferenceCenterUrl($contact),
            campaign: null,
            escape: $escape,
            rawHtml: $rawHtml,
        ));

        return app(EvaluateSmartContentBlocksAction::class)->execute($html, $contact);
    }

    /**
     * The values for every merge tag the package registers with the mail builder, plus
     * `unsubscribe_url` and the campaign's own `campaign.subject` and `campaign.name`.
     *
     * Missing values get the old fallbacks for the original tags (`there`, `your organization`)
     * and an empty string for the rest, so an unfilled tag never reaches a customer.
     *
     * @return array<string, mixed>
     */
    protected function mergeContext(?Contact $contact, ?Company $company, string $email, string $unsubscribeUrl, ?Campaign $campaign, bool $escape = true, string $rawHtml = ''): array
    {
        $firstName = $contact?->first_name;
        $lastName = $contact?->last_name;
        $owner = $contact?->owner;

        $context = [
            'contact' => [
                'first_name' => $firstName !== null && $firstName !== '' ? $firstName : 'there',
                'last_name' => (string) $lastName,
                'full_name' => trim($firstName.' '.$lastName),
                'email' => $email,
                'job_title' => (string) $contact?->job_title,
                'phone' => (string) $contact?->phone,
                'lifecycle_stage' => $contact?->lifecycle_stage?->value ?? '',
            ],
            'company' => [
                'name' => $company?->name !== null && $company->name !== '' ? $company->name : 'your organization',
                'domain' => (string) $company?->domain,
                'industry' => (string) $company?->industry,
            ],
            'sender' => [
                'name' => $owner !== null ? UserModel::displayName($owner, '') : (string) $campaign?->sender_name,
                'email' => (string) ($campaign?->sender_email ?? config('odden-marketing.defaults.sender_email')),
            ],
            'campaign' => [
                'subject' => (string) $campaign?->subject,
                'name' => (string) $campaign?->name,
            ],
            'unsubscribe_url' => $unsubscribeUrl,
            'event' => $this->eventContext($rawHtml, $contact),
        ];

        if (! $escape) {
            return $context;
        }

        array_walk_recursive($context, function (mixed &$value): void {
            $value = e((string) $value);
        });

        return $context;
    }

    /**
     * Signed RSVP tokens for the events a template refers to as `{{event.<id>.rsvp_token}}`,
     * issued for this contact so an AMP RSVP form can post them to `/amp/rsvp`.
     *
     * @return array<int, array{rsvp_token: string}>
     */
    protected function eventContext(string $rawHtml, ?Contact $contact): array
    {
        if ($contact === null || ! preg_match_all('/\{\{\s*event\.(\d+)\.rsvp_token\s*\}\}/', $rawHtml, $matches)) {
            return [];
        }

        $tokens = [];
        foreach (array_unique($matches[1]) as $id) {
            $event = MarketingEvent::query()->find((int) $id);
            if ($event !== null) {
                $tokens[$event->id] = ['rsvp_token' => $event->rsvpTokenFor($contact)];
            }
        }

        return $tokens;
    }
}
