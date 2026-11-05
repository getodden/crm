<?php

declare(strict_types=1);

namespace Odden\Livewire\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Odden\Livewire\Search\SearchHit;

interface SearchSource
{
    public function label(): string;

    public function screenKey(): string;

    /**
     * @return list<SearchHit>
     */
    public function search(string $term, Authenticatable $user, int $limit): array;
}
