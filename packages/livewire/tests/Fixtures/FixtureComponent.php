<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Livewire\Attributes\Layout;
use Livewire\Component;
use Odden\Livewire\Concerns\AuthorizesScreen;

#[Layout('odden-livewire::layouts.app')]
abstract class FixtureComponent extends Component
{
    use AuthorizesScreen;

    public function render(): string
    {
        return '<div>fixture-component</div>';
    }
}
