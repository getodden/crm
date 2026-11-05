<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class ConfigurablePolicy
{
    /** @var list<string> */
    public static array $denied = [];

    public function viewAny(User $user): bool
    {
        return $this->allows('viewAny');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->allows('view');
    }

    public function create(User $user): bool
    {
        return $this->allows('create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allows('update');
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->allows('delete');
    }

    private function allows(string $ability): bool
    {
        return ! in_array($ability, static::$denied, true);
    }
}
