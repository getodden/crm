<?php

declare(strict_types=1);

namespace Odden\Marketing\Actions;

use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

class EvaluateSmartContentBlocksAction
{
    /**
     * Parse and render smart dynamic content blocks based on contact and company attributes.
     *
     * Supports:
     * - Block tags: [smart tier="tier_1"] Content [/smart][smart default] Default [/smart]
     * - Blade-like tags: @smart(stage="customer") Customer Content @smart(default) Default @endsmart
     * - Inline smart tokens: {{smart:stage=customer?Special Offer:Default Offer}}
     */
    public function execute(string $html, ?Contact $contact): string
    {
        /** @var Company|null $company */
        $company = $contact !== null ? $contact->companies()->first() : null;

        // 1. Process Blade-like @smart(...) ... @endsmart into standard [smart ...] tags. One @endsmart
        // closes the whole group, so each @smart(...) block runs up to the next @smart( or @endsmart.
        if (str_contains($html, '@smart')) {
            $html = (string) preg_replace_callback(
                '/@smart\(([^)]*)\)([\s\S]*?)(?=@smart\(|@endsmart)/',
                fn (array $m): string => '[smart '.trim($m[1]).']'.trim($m[2]).'[/smart]',
                $html
            );
            $html = str_replace('@endsmart', '', $html);
        }

        // 2. Process [smart ...] blocks
        if (str_contains($html, '[smart')) {
            $html = (string) preg_replace_callback(
                '/((?:\[smart\s*.*?\][\s\S]*?\[\/smart\]\s*)+)/is',
                function (array $matches) use ($contact, $company): string {
                    $blockGroup = $matches[1];

                    preg_match_all('/\[smart\s*(.*?)\]([\s\S]*?)\[\/smart\]/is', $blockGroup, $individualBlocks, PREG_SET_ORDER);

                    $defaultContent = '';
                    $matchedContent = null;

                    foreach ($individualBlocks as $block) {
                        $attrString = trim($block[1]);
                        $content = $block[2];

                        if (empty($attrString) || strtolower($attrString) === 'default') {
                            $defaultContent = $content;

                            continue;
                        }

                        $attrs = $this->parseAttributes($attrString);

                        if ($contact !== null && $this->matchesRule($attrs, $contact, $company)) {
                            $matchedContent = $content;
                            break;
                        }
                    }

                    return $matchedContent ?? $defaultContent;
                },
                $html
            );
        }

        // 3. Process inline smart tokens: {{smart:rule_key=expected?true_val:false_val}}
        if (str_contains($html, '{{smart:')) {
            $html = (string) preg_replace_callback(
                '/\{\{smart:([a-zA-Z0-9_\-]+)=([^\?]+)\?([^:]*):([^\}]+)\}\}/i',
                function (array $matches) use ($contact, $company): string {
                    $ruleKey = strtolower(trim($matches[1]));
                    $expected = trim($matches[2]);
                    $trueVal = $matches[3];
                    $falseVal = $matches[4];

                    if ($contact === null) {
                        return $falseVal;
                    }

                    $rules = [$ruleKey => $expected];

                    return $this->matchesRule($rules, $contact, $company) ? $trueVal : $falseVal;
                },
                $html
            );
        }

        return $html;
    }

    /**
     * Parse key="value" or key='value' attribute pairs.
     *
     * @return array<string, string>
     */
    protected function parseAttributes(string $attrString): array
    {
        $attrs = [];
        preg_match_all('/([a-zA-Z0-9_\-]+)=["\']([^"\']*)["\']/', $attrString, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $attrs[strtolower($match[1])] = (string) $match[2];
        }

        return $attrs;
    }

    /**
     * Determine if contact or their associated company satisfies the smart block rules.
     *
     * @param  array<string, string>  $rules
     */
    protected function matchesRule(array $rules, Contact $contact, ?Company $company): bool
    {
        foreach ($rules as $key => $expectedValue) {
            $matched = match ($key) {
                'tier', 'account_tier' => $company !== null && strtolower((string) $company->account_tier) === strtolower($expectedValue),
                'stage', 'lifecycle_stage' => strtolower($contact->lifecycle_stage->value) === strtolower($expectedValue),
                'status', 'lead_status' => strtolower($contact->lead_status->value) === strtolower($expectedValue),
                'surge', 'intent_surge' => $company !== null && (bool) $company->intent_surge === filter_var($expectedValue, FILTER_VALIDATE_BOOLEAN),
                'industry' => $company !== null && strtolower((string) $company->industry) === strtolower($expectedValue),
                'min_score' => $contact->lead_score >= (int) $expectedValue,
                'max_score' => $contact->lead_score <= (int) $expectedValue,
                'has_company' => ($company !== null) === filter_var($expectedValue, FILTER_VALIDATE_BOOLEAN),
                default => strtolower((string) ($contact->getProperty($key) ?? $contact->{$key} ?? '')) === strtolower($expectedValue),
            };

            if (! $matched) {
                return false;
            }
        }

        return true;
    }
}
