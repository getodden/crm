<?php

declare(strict_types=1);

namespace Odden\Livewire\Bell;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Odden\Livewire\Contracts\BellFeed;

class NotificationBell extends Component
{
    public bool $open = false;

    public function openNotification(string $id): void
    {
        $user = $this->visibleUser();

        if ($user === null) {
            return;
        }

        $item = $this->feed()->find($user, $id);

        if ($item === null) {
            return;
        }

        $this->feed()->markRead($user, $item->id);

        if ($item->url !== null) {
            $this->redirect($item->url);
        }
    }

    public function markAsRead(string $id): void
    {
        $user = $this->visibleUser();

        if ($user === null) {
            return;
        }

        $item = $this->feed()->find($user, $id);

        if ($item !== null) {
            $this->feed()->markRead($user, $item->id);
        }
    }

    public function markAllAsRead(): void
    {
        $user = $this->visibleUser();

        if ($user !== null) {
            $this->feed()->markAllRead($user);
        }
    }

    public function render(): View
    {
        $user = $this->visibleUser();

        return view('odden-livewire::bell.notification-bell', [
            'visible' => $user !== null,
            'unread' => $user === null ? 0 : $this->feed()->unreadCount($user),
            'items' => $user === null || ! $this->open ? [] : $this->feed()->items($user, $this->limit()),
        ]);
    }

    private function visibleUser(): ?Authenticatable
    {
        $user = Auth::user();

        return $user !== null && $this->feed()->visible($user) ? $user : null;
    }

    private function feed(): BellFeed
    {
        return app(BellFeed::class);
    }

    private function limit(): int
    {
        return max(1, (int) config('odden-livewire.bell.limit', 20));
    }
}
