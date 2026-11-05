<?php

declare(strict_types=1);

namespace Odden\Livewire\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

interface WriteGuard
{
    public function refusal(Authenticatable $user): ?string;
}
