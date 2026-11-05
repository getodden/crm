<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Screens\Screen;
use Odden\Core\Screens\ScreenDrawer;
use Odden\Core\Screens\ScreenShell;

final class FixtureShell implements ScreenShell
{
    public const NAME = 'fixture';

    public function draws(Screen $screen, ScreenDrawer $drawer, string $class): bool
    {
        return true;
    }

    public function url(Screen $screen, ScreenDrawer $drawer, string $class, Model|int|string|null $record, array $parameters, ?Model $tenant, bool $absolute): string
    {
        return "https://fixture.test/{$screen->key}";
    }
}
