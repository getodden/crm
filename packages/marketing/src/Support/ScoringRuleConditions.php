<?php

declare(strict_types=1);

namespace Odden\Marketing\Support;

use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;

/**
 * Evaluates a lead scoring rule's `conditions` against the contact, their company and the event context.
 *
 * `conditions` is a list of `{field, operator, value}` entries that must all match, or the
 * shorthand `{"field": value}` map where each entry is an equality check (an array value means
 * "any of"). Fields look like `contact.lifecycle_stage`, `company.industry` or `context.path`
 * (`contact` and `company` read an attribute first, then a custom property).
 *
 * Operators: `=` (default), `!=`, `>`, `>=`, `<`, `<=`, `contains`, `starts_with`, `in`, `not_in`.
 * Comparisons of strings ignore case. A condition on something that has no value never matches.
 */
final class ScoringRuleConditions
{
    /**
     * @param  array<int|string, mixed>|null  $conditions
     * @param  array<string, mixed>  $context
     */
    public static function matches(?array $conditions, ?Contact $contact, ?Company $company, array $context = []): bool
    {
        if ($conditions === null || $conditions === []) {
            return true;
        }

        foreach (self::normalize($conditions) as $condition) {
            $actual = self::resolve($condition['field'], $contact, $company, $context);

            if (! self::compare($actual, $condition['operator'], $condition['value'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<int|string, mixed>  $conditions
     * @return list<array{field: string, operator: string, value: mixed}>
     */
    private static function normalize(array $conditions): array
    {
        $normalized = [];

        foreach ($conditions as $key => $condition) {
            if (is_array($condition) && isset($condition['field'])) {
                $normalized[] = [
                    'field' => (string) $condition['field'],
                    'operator' => strtolower((string) ($condition['operator'] ?? '=')),
                    'value' => $condition['value'] ?? null,
                ];
            } elseif (is_string($key)) {
                $normalized[] = ['field' => $key, 'operator' => is_array($condition) ? 'in' : '=', 'value' => $condition];
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function resolve(string $field, ?Contact $contact, ?Company $company, array $context): mixed
    {
        [$source, $key] = array_pad(explode('.', $field, 2), 2, '');

        $value = match (strtolower($source)) {
            'contact' => self::fromModel($contact, $key),
            'company' => self::fromModel($company, $key),
            'context' => data_get($context, $key),
            default => null,
        };

        return $value instanceof \BackedEnum ? $value->value : $value;
    }

    private static function fromModel(Contact|Company|null $model, string $key): mixed
    {
        if ($model === null || $key === '') {
            return null;
        }

        return $model->getAttribute($key) ?? $model->getProperty($key);
    }

    private static function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        if ($actual === null || $actual === '') {
            return false;
        }

        $norm = fn (mixed $value): mixed => is_string($value) ? mb_strtolower(trim($value)) : $value;
        $actualNorm = $norm($actual);

        if (in_array($operator, ['in', 'not_in'], true)) {
            $list = array_map($norm, (array) $expected);
            $found = in_array($actualNorm, $list, false);

            return $operator === 'in' ? $found : ! $found;
        }

        $expectedNorm = $norm($expected);

        return match ($operator) {
            '=', '==' => is_numeric($actualNorm) && is_numeric($expectedNorm) ? (float) $actualNorm === (float) $expectedNorm : $actualNorm === $expectedNorm,
            '!=' => ! (is_numeric($actualNorm) && is_numeric($expectedNorm) ? (float) $actualNorm === (float) $expectedNorm : $actualNorm === $expectedNorm),
            '>' => is_numeric($actualNorm) && is_numeric($expectedNorm) && (float) $actualNorm > (float) $expectedNorm,
            '>=' => is_numeric($actualNorm) && is_numeric($expectedNorm) && (float) $actualNorm >= (float) $expectedNorm,
            '<' => is_numeric($actualNorm) && is_numeric($expectedNorm) && (float) $actualNorm < (float) $expectedNorm,
            '<=' => is_numeric($actualNorm) && is_numeric($expectedNorm) && (float) $actualNorm <= (float) $expectedNorm,
            'contains' => is_string($actualNorm) && is_string($expectedNorm) && $expectedNorm !== '' && str_contains($actualNorm, $expectedNorm),
            'starts_with' => is_string($actualNorm) && is_string($expectedNorm) && $expectedNorm !== '' && str_starts_with($actualNorm, $expectedNorm),
            default => false,
        };
    }
}
