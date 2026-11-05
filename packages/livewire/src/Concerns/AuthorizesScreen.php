<?php

declare(strict_types=1);

namespace Odden\Livewire\Concerns;

use Illuminate\Support\Facades\Auth;
use LogicException;
use Odden\Core\Screens\Screens;

trait AuthorizesScreen
{
    use GuardsWrites;

    abstract public static function screenKey(): string;

    public function bootAuthorizesScreen(): void
    {
        $key = static::screenKey();

        if (Screens::get($key)->record) {
            throw new LogicException("The screen [{$key}] needs a record, and record screens are not supported yet.");
        }

        $user = Auth::user();

        abort_unless($user !== null && Screens::canOpen($key, $user), 403);
    }
}
