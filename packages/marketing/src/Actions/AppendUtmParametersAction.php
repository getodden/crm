<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Marketing\Models\Campaign;
use Odden\Marketing\Models\CampaignRecipient;

class AppendUtmParametersAction
{
    /**
     * Append Google Analytics / RevOps UTM parameters to a single target URL.
     */
    public function execute(string $url, Campaign $campaign, ?string $variant = null, ?string $term = null): string
    {
        if (
            str_starts_with($url, 'mailto:') ||
            str_starts_with($url, 'tel:') ||
            str_starts_with($url, '#') ||
            str_contains($url, CampaignRecipient::unsubscribePathPrefix())
        ) {
            return $url;
        }

        $campaignSlug = $campaign->utmCampaignSlug();

        $utmParams = [
            'utm_source' => 'odden',
            'utm_medium' => 'email',
            'utm_campaign' => $campaignSlug,
        ];

        if ($variant !== null && $variant !== '') {
            $utmParams['utm_content'] = 'variant_'.mb_strtolower($variant);
        }

        if ($term !== null && $term !== '') {
            $utmParams['utm_term'] = $term;
        }

        // Split URL into base, query, and fragment
        $parts = parse_url($url);
        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        // Keep the link's own query string byte for byte (re-encoding it through
        // parse_str() would rename keys such as "a.b"), and append only the UTM
        // parameters it does not already carry.
        $existingQueryString = $parts['query'] ?? '';
        $existingQuery = [];
        parse_str($existingQueryString, $existingQuery);

        $missingUtmParams = array_diff_key($utmParams, $existingQuery);
        $queryString = implode('&', array_filter(
            [$existingQueryString, http_build_query($missingUtmParams, '', '&', PHP_QUERY_RFC3986)],
            fn (string $part): bool => $part !== '',
        ));

        $scheme = $parts['scheme'];
        $userInfo = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = $parts['path'] ?? '';
        $fragment = isset($parts['fragment']) ? '#'.$parts['fragment'] : '';

        return "{$scheme}://{$userInfo}{$host}{$port}{$path}".($queryString !== '' ? "?{$queryString}" : '').$fragment;
    }

    /**
     * Automatically append UTM parameters to all hyperlinks in an HTML message body.
     */
    public function appendHtmlLinks(string $html, Campaign $campaign, ?string $variant = null): string
    {
        if (! $campaign->utm_auto_tag) {
            return $html;
        }

        return (string) preg_replace_callback(
            '/<a\s+([^>]*?)href=(["\'])(.*?)\2([^>]*)>/i',
            function (array $matches) use ($campaign, $variant): string {
                $before = $matches[1];
                $quote = $matches[2];
                // The attribute value is HTML: decode it to the real URL, tag it, and
                // escape it once on the way back out.
                $originalUrl = html_entity_decode($matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $after = $matches[4];

                $taggedUrl = $this->execute($originalUrl, $campaign, $variant);

                return "<a {$before}href={$quote}".htmlspecialchars($taggedUrl, ENT_QUOTES, 'UTF-8')."{$quote}{$after}>";
            },
            $html
        );
    }
}
