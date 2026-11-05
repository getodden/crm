<?php

declare(strict_types=1);

namespace Odden\Livewire\Search;

final readonly class SearchHit
{
    /**
     * @param  list<string>  $details
     */
    public function __construct(
        public string $title,
        public string $url,
        public array $details = [],
    ) {}
}
