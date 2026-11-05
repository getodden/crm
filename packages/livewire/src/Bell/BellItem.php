<?php

declare(strict_types=1);

namespace Odden\Livewire\Bell;

use Carbon\CarbonImmutable;

final readonly class BellItem
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $body,
        public ?string $url,
        public ?string $actionLabel,
        public bool $read,
        public CarbonImmutable $createdAt,
    ) {}
}
