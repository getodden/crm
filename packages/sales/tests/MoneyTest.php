<?php

declare(strict_types=1);

namespace Odden\Sales\Tests;

use Odden\Sales\Support\Money;

class MoneyTest extends TestCase
{
    public function test_formats_known_currencies_with_their_symbol(): void
    {
        $this->assertSame('$1,234.50', Money::format(1234.5, 'USD'));
        $this->assertSame('€1,234.50', Money::format(1234.5, 'EUR'));
        $this->assertSame('£10.00', Money::format('10', 'gbp'));
    }

    public function test_unknown_currencies_are_prefixed_with_their_code(): void
    {
        $this->assertSame('CHF 99.00', Money::format(99, 'CHF'));
    }

    public function test_falls_back_to_the_configured_default_currency(): void
    {
        config(['odden-sales.default_currency' => 'EUR']);

        $this->assertSame('€5.00', Money::format(5));
        $this->assertSame('€5.00', Money::format(5, ''));
    }

    public function test_supports_custom_decimals(): void
    {
        $this->assertSame('$62,500', Money::format(62500, 'USD', 0));
    }
}
