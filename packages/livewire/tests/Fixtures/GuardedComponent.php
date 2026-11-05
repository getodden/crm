<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Livewire\Attributes\Json;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Odden\Livewire\Attributes\Reads;
use Odden\Livewire\Concerns\GuardsWrites;
use Odden\Ui\Livewire\Concerns\WithConfirmation;

final class GuardedComponent extends Component
{
    use GuardsWrites, WithConfirmation, WithPagination;

    public int $count = 0;

    public string $name = '';

    public function bump(): void
    {
        $this->count++;
    }

    #[Json]
    public function jsonBump(): int
    {
        return ++$this->count;
    }

    #[Reads]
    public function peek(): void
    {
        $this->name = 'peeked';
    }

    public function askToBump(): void
    {
        $this->confirm('bump', [], 'Bump', 'Bump the count?', 'Bump');
    }

    #[On('poke')]
    public function poke(): void
    {
        $this->count++;
    }

    #[On('look')]
    #[Reads]
    public function look(): void
    {
        $this->name = 'looked';
    }

    public function render(): string
    {
        return '<div>guarded</div>';
    }
}
