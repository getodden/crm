<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures\Screens;

use Odden\Livewire\Tests\Fixtures\FixtureComponent;

final class WrongKey extends FixtureComponent
{
    public static function screenKey(): string
    {
        return 'contacts.view';
    }
}
