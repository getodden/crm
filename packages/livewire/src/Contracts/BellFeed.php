<?php

declare(strict_types=1);

namespace Odden\Livewire\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Odden\Livewire\Bell\BellItem;

interface BellFeed
{
    public function visible(Authenticatable $user): bool;

    /**
     * @return list<BellItem>
     */
    public function items(Authenticatable $user, int $limit): array;

    public function unreadCount(Authenticatable $user): int;

    public function find(Authenticatable $user, string $id): ?BellItem;

    public function markRead(Authenticatable $user, string $id): void;

    public function markAllRead(Authenticatable $user): void;
}
