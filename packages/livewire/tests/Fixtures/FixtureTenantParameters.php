<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Screens\ScreenTenantParameters;

final class FixtureTenantParameters implements ScreenTenantParameters
{
    public function for(Model $tenant): array
    {
        return ['workspace' => 'acme'];
    }
}
