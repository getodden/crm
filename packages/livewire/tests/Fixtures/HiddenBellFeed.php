<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Fixtures;

use Illuminate\Contracts\Auth\Authenticatable;
use Odden\Livewire\Bell\BellItem;
use Odden\Livewire\Contracts\BellFeed;

final class HiddenBellFeed implements BellFeed
{
    public static int $calls = 0;

    public function visible(Authenticatable $user): bool
    {
        return false;
    }

    public function items(Authenticatable $user, int $limit): array
    {
        self::$calls++;

        return [];
    }

    public function unreadCount(Authenticatable $user): int
    {
        self::$calls++;

        return 0;
    }

    public function find(Authenticatable $user, string $id): ?BellItem
    {
        self::$calls++;

        return null;
    }

    public function markRead(Authenticatable $user, string $id): void
    {
        self::$calls++;
    }

    public function markAllRead(Authenticatable $user): void
    {
        self::$calls++;
    }
}
