<?php

declare(strict_types=1);

use Odden\Livewire\Search\Sources\CompanySearch;
use Odden\Livewire\Search\Sources\ContactSearch;

return [
    'logout_route' => 'logout',

    'search' => [
        'enabled' => true,
        'sources' => [
            ContactSearch::class,
            CompanySearch::class,
        ],
    ],

    'bell' => [
        'enabled' => true,
        'limit' => 20,
    ],
];
