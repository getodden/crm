<?php

declare(strict_types=1);

namespace Odden\Livewire\Search;

use InvalidArgumentException;
use Odden\Livewire\Contracts\SearchSource;

final class SearchSources
{
    /**
     * @return list<SearchSource>
     */
    public function configured(): array
    {
        $sources = [];

        foreach ((array) config('odden-livewire.search.sources', []) as $class) {
            $source = is_string($class) ? app($class) : null;

            if (! $source instanceof SearchSource) {
                $name = is_string($class) ? $class : get_debug_type($class);

                throw new InvalidArgumentException("The search source [{$name}] does not implement ".SearchSource::class.'.');
            }

            $sources[] = $source;
        }

        return $sources;
    }
}
