<?php

declare(strict_types=1);

namespace Odden\Sales\Services;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Sales\Models\Deal;
use Odden\Sales\Support\Money;

class TemplateParser
{
    /**
     * Parse a plain-text template string (e.g. a subject) by replacing {{ variable }} merge tags.
     * Values are inserted as-is; use parseHtml() for HTML output.
     *
     * @param  array<string, mixed>  $context
     */
    public function parse(string $template, array $context = []): string
    {
        return $this->replaceTags($template, $context, escape: false);
    }

    /**
     * Parse an HTML template string (e.g. body_html), HTML-escaping each merge value.
     *
     * @param  array<string, mixed>  $context
     */
    public function parseHtml(string $template, array $context = []): string
    {
        return $this->replaceTags($template, $context, escape: true);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function replaceTags(string $template, array $context, bool $escape): string
    {
        return (string) preg_replace_callback('/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/', function (array $matches) use ($context, $escape): string {
            $value = (string) ($this->resolveValue($matches[1], $context) ?? '');

            return $escape ? e($value) : $value;
        }, $template);
    }

    /**
     * Build standard CRM merge context from Contact, Deal, and User models.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function buildContext(?Contact $contact = null, ?Deal $deal = null, ?Model $user = null, array $extra = []): array
    {
        $context = [];

        if ($contact !== null) {
            $context['contact'] = [
                'id' => $contact->id,
                'first_name' => $contact->first_name ?? '',
                'last_name' => $contact->last_name ?? '',
                'name' => $contact->full_name,
                'full_name' => $contact->full_name,
                'email' => $contact->email,
                'phone' => $contact->phone ?? '',
                'job_title' => $contact->job_title ?? '',
                'title' => $contact->job_title ?? '',
                'timezone' => $contact->timezone ?? '',
                'lead_status' => $contact->lead_status->label(),
                'properties' => $contact->properties ?? [],
            ];

            // Company from first contact association if present
            /** @var Company|null $company */
            $company = $contact->companies->first();
            if ($company !== null) {
                $context['company'] = [
                    'id' => $company->id,
                    'name' => $company->name,
                    'domain' => $company->domain ?? '',
                    'industry' => $company->industry ?? '',
                    'properties' => $company->properties ?? [],
                ];
            }
        }

        if ($deal !== null) {
            $context['deal'] = [
                'id' => $deal->id,
                'name' => $deal->name,
                'amount' => $deal->amount,
                'currency' => $deal->currency,
                'formatted_amount' => Money::format($deal->amount, $deal->currency),
                'stage' => $deal->stage->name,
                'expected_close_date' => $deal->expected_close_date?->format('Y-m-d') ?? '',
                'days_in_stage' => $deal->daysInCurrentStage(),
                'properties' => $deal->properties ?? [],
            ];
        }

        if ($user !== null) {
            $context['user'] = [
                'id' => $user->getKey(),
                'name' => $user->getAttribute('name'),
                'email' => $user->getAttribute('email'),
            ];
            $context['sender'] = $context['user'];
            $context['rep'] = $context['user'];
            $context['owner'] = $context['user'];
        }

        return array_merge($context, $extra);
    }

    /**
     * Resolve a dot-notation key from the context array.
     *
     * @param  array<string, mixed>  $context
     */
    protected function resolveValue(string $key, array $context): ?string
    {
        $segments = explode('.', $key);
        $current = $context;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                // Check if properties array has the custom field
                if (is_array($current) && isset($current['properties']) && is_array($current['properties']) && array_key_exists($segment, $current['properties'])) {
                    $val = $current['properties'][$segment];

                    return is_scalar($val) ? (string) $val : null;
                }

                return null;
            }

            $current = $current[$segment];
        }

        if (is_scalar($current)) {
            return (string) $current;
        }

        return null;
    }
}
