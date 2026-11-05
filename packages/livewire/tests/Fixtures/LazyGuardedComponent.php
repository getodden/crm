<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Livewire\Attributes\Lazy;
use Livewire\Component;
use Odden\Livewire\Concerns\GuardsWrites;

#[Lazy]
final class LazyGuardedComponent extends Component
{
    use GuardsWrites;

    public function render(): string
    {
        return '<div>lazy loaded</div>';
    }
}
