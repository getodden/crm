<?php

declare(strict_types=1);

namespace Odden\Sales\Support;

class Money
{
    /**
     * Currency symbols used as a prefix; any other currency is prefixed with its ISO code.
     *
     * @var array<string, string>
     */
    protected const SYMBOLS = [
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
        'JPY' => '¥',
    ];

    /**
     * Format an amount in the given currency, falling back to the configured default currency.
     */
    public static function format(float|int|string|null $amount, ?string $currency = null, int $decimals = 2): string
    {
        $currency = strtoupper(trim((string) $currency));
        if ($currency === '') {
            $currency = strtoupper((string) config('odden-sales.default_currency', 'USD'));
        }

        $number = number_format((float) $amount, $decimals);

        return isset(self::SYMBOLS[$currency])
            ? self::SYMBOLS[$currency].$number
            : $currency.' '.$number;
    }
}
