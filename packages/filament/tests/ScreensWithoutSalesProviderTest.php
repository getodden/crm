<?php

declare(strict_types=1);

namespace Odden\Filament\Tests;

use Odden\Core\Screens\Screens;
use Odden\Sales\SalesServiceProvider;

class ScreensWithoutSalesProviderTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return array_values(array_filter(parent::getPackageProviders($app), fn (string $provider): bool => $provider !== SalesServiceProvider::class));
    }

    public function test_the_application_boots_and_no_sales_key_is_drawn(): void
    {
        $this->assertTrue(Screens::isFrozen());
        $this->assertTrue(Screens::has('contacts.index'));
        $this->assertFalse(Screens::has('deals.index'));
        $this->assertTrue(Screens::available('contacts.index'));
    }
}
