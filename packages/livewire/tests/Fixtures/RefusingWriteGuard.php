<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Odden\Livewire\Contracts\WriteGuard;

final class RefusingWriteGuard implements WriteGuard
{
    public const MESSAGE = 'This session is read-only.';

    public function refusal(Authenticatable $user): ?string
    {
        return self::MESSAGE;
    }
}
