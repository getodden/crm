<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('odden-livewire::layouts.app')]
class PlainComponent extends Component
{
    public function render(): string
    {
        return '<div>plain-component</div>';
    }
}
