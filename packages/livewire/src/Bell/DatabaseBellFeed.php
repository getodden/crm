<?php

declare(strict_types=1);

namespace Odden\Livewire\Bell;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Odden\Core\Actions\MarkNotificationsReadAction;
use Odden\Livewire\Contracts\BellFeed;

class DatabaseBellFeed implements BellFeed
{
    public function __construct(private readonly MarkNotificationsReadAction $markNotificationsRead) {}

    public function visible(Authenticatable $user): bool
    {
        return $user instanceof Model && method_exists($user, 'notifications');
    }

    public function items(Authenticatable $user, int $limit): array
    {
        if (! $user instanceof Model || ! $this->visible($user)) {
            return [];
        }

        return array_values(
            $this->rows($user)
                ->orderByDesc('created_at')
                ->limit(max(0, $limit))
                ->get()
                ->map(fn (DatabaseNotification $notification): BellItem => $this->item($notification))
                ->all()
        );
    }

    public function unreadCount(Authenticatable $user): int
    {
        if (! $user instanceof Model || ! $this->visible($user)) {
            return 0;
        }

        return $this->rows($user)->whereNull('read_at')->count();
    }

    public function find(Authenticatable $user, string $id): ?BellItem
    {
        if (! $user instanceof Model || ! $this->visible($user) || ! Str::isUuid($id)) {
            return null;
        }

        $notification = $this->rows($user)->whereKey($id)->first();

        return $notification instanceof DatabaseNotification ? $this->item($notification) : null;
    }

    public function markRead(Authenticatable $user, string $id): void
    {
        if ($user instanceof Model && $this->visible($user)) {
            $this->markNotificationsRead->execute($user, $id);
        }
    }

    public function markAllRead(Authenticatable $user): void
    {
        if ($user instanceof Model && $this->visible($user)) {
            $this->markNotificationsRead->execute($user);
        }
    }

    /**
     * @return Builder<DatabaseNotification>
     */
    private function rows(Model $user): Builder
    {
        return DatabaseNotification::query()
            ->where('notifiable_type', $user->getMorphClass())
            ->where('notifiable_id', $user->getKey());
    }

    private function item(DatabaseNotification $notification): BellItem
    {
        $stored = $notification->getAttribute('data');
        $data = is_array($stored) ? $stored : [];
        $action = is_array($data['actions'][0] ?? null) ? $data['actions'][0] : [];
        $url = $this->safeUrl($action['url'] ?? $data['url'] ?? null);

        return new BellItem(
            id: (string) $notification->getKey(),
            title: $this->text($data['title'] ?? null) ?? __('Notification'),
            body: $this->text($data['body'] ?? null),
            url: $url,
            actionLabel: $url === null ? null : $this->text($action['label'] ?? null),
            read: $notification->read(),
            createdAt: $this->createdAt($notification),
        );
    }

    private function createdAt(DatabaseNotification $notification): CarbonImmutable
    {
        $created = $notification->getAttribute('created_at');

        return $created instanceof DateTimeInterface ? CarbonImmutable::instance($created) : CarbonImmutable::now();
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function safeUrl(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (str_starts_with($value, '/') && ! str_starts_with($value, '//') && ! str_starts_with($value, '/\\')) {
            return $value;
        }

        return preg_match('#^https?://[^\s]+$#i', $value) === 1 ? $value : null;
    }
}
