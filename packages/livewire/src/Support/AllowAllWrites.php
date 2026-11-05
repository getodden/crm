<?php

declare(strict_types=1);

namespace Odden\Livewire\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Odden\Livewire\Contracts\WriteGuard;

final class AllowAllWrites implements WriteGuard
{
    public function refusal(Authenticatable $user): ?string
    {
        return null;
    }
}
