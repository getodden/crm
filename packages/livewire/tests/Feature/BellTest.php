<?php

declare(strict_types=1);

namespace Odden\Livewire\Tests\Feature;

use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Odden\Livewire\Bell\NotificationBell;
use Odden\Livewire\Contracts\BellFeed;
use Odden\Livewire\Tests\Fixtures\HiddenBellFeed;
use Odden\Livewire\Tests\Fixtures\User;
use Odden\Livewire\Tests\RoutedTestCase;

class BellTest extends RoutedTestCase
{
    /**
     * @param  array<string, mixed>  $data
     */
    private function notify(User $user, array $data = ['title' => 'Ticket assigned'], bool $read = false, int $minutesAgo = 0): DatabaseNotification
    {
        return DatabaseNotification::query()->create([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->getKey(),
            'data' => $data,
            'read_at' => $read ? now() : null,
            'created_at' => now()->subMinutes($minutesAgo),
        ]);
    }

    private function bell(User $user): Testable
    {
        return Livewire::actingAs($user)->test(NotificationBell::class);
    }

    public function test_it_lists_only_the_signed_in_users_notifications_newest_first(): void
    {
        $user = User::factory()->create();
        $this->notify($user, ['title' => 'Older'], minutesAgo: 10);
        $this->notify($user, ['title' => 'Newer'], minutesAgo: 1);
        $this->notify(User::factory()->create(), ['title' => 'Someone else']);

        $this->bell($user)
            ->set('open', true)
            ->assertSeeInOrder(['Newer', 'Older'])
            ->assertDontSee('Someone else');
    }

    public function test_the_default_feed_reads_the_filament_stored_format(): void
    {
        $user = User::factory()->create();
        $this->notify($user, [
            'title' => 'Reply received',
            'body' => 'Ana replied',
            'actions' => [['name' => 'view', 'label' => 'View ticket', 'url' => '/crm/tickets/4']],
        ]);

        $this->bell($user)
            ->set('open', true)
            ->assertSee('Reply received')
            ->assertSee('Ana replied')
            ->assertSee('View ticket')
            ->assertSee('href="/crm/tickets/4"', false);
    }

    public function test_a_notification_without_a_title_is_called_notification(): void
    {
        $user = User::factory()->create();
        $this->notify($user, ['body' => 'Just a body']);

        $this->bell($user)->set('open', true)->assertSee('Notification')->assertSee('Just a body');
    }

    public function test_an_unsafe_link_is_not_shown_or_followed(): void
    {
        $user = User::factory()->create();
        $notification = $this->notify($user, ['title' => 'Odd', 'url' => 'javascript:alert(1)']);

        $this->bell($user)
            ->set('open', true)
            ->assertDontSee('javascript:', false)
            ->call('openNotification', $notification->id)
            ->assertNoRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_the_unread_count_is_in_the_buttons_accessible_name_and_polls(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $this->notify($user);
        $this->notify($user, read: true);

        $this->bell($user)
            ->assertSee('aria-label="Notifications, 2 unread"', false)
            ->assertSeeHtml('wire:poll.30s.visible');
    }

    public function test_without_unread_the_name_is_plain_and_the_empty_state_shows(): void
    {
        $this->bell(User::factory()->create())
            ->set('open', true)
            ->assertSee('aria-label="Notifications"', false)
            ->assertSee('No notifications')
            ->assertDontSee('Mark all as read');
    }

    public function test_opening_a_notification_marks_it_read_and_redirects_to_its_stored_url(): void
    {
        $user = User::factory()->create();
        $notification = $this->notify($user, ['title' => 'Go', 'url' => 'https://example.test/tickets/9']);
        $other = $this->notify($user);

        $this->bell($user)
            ->call('openNotification', $notification->id)
            ->assertRedirect('https://example.test/tickets/9');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertNull($other->fresh()->read_at);
    }

    public function test_opening_a_notification_without_a_link_only_marks_it_read(): void
    {
        $user = User::factory()->create();
        $notification = $this->notify($user);

        $this->bell($user)->call('openNotification', $notification->id)->assertNoRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_another_users_id_neither_marks_nor_redirects(): void
    {
        $user = User::factory()->create();
        $theirs = $this->notify(User::factory()->create(), ['title' => 'Theirs', 'url' => '/secret']);

        $this->bell($user)
            ->call('openNotification', $theirs->id)
            ->assertNoRedirect()
            ->call('markAsRead', $theirs->id);

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_an_id_that_is_not_a_notification_changes_nothing(): void
    {
        $user = User::factory()->create();
        $mine = $this->notify($user);

        $this->bell($user)
            ->call('openNotification', "x' or '1'='1")
            ->assertNoRedirect()
            ->call('markAsRead', 'nope');

        $this->assertNull($mine->fresh()->read_at);
    }

    public function test_mark_as_read_marks_one(): void
    {
        $user = User::factory()->create();
        $first = $this->notify($user);
        $second = $this->notify($user);

        $this->bell($user)->call('markAsRead', $first->id);

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNull($second->fresh()->read_at);
    }

    public function test_mark_all_as_read_clears_the_count_and_leaves_other_users_alone(): void
    {
        $user = User::factory()->create();
        $this->notify($user);
        $this->notify($user);
        $theirs = $this->notify(User::factory()->create());

        $this->bell($user)
            ->assertSee('Notifications, 2 unread')
            ->call('markAllAsRead')
            ->assertSee('aria-label="Notifications"', false);

        $this->assertNull($theirs->fresh()->read_at);
    }

    public function test_the_component_state_carries_no_notification_data(): void
    {
        $user = User::factory()->create();
        $this->notify($user, ['title' => 'Secret title', 'url' => 'https://example.test/x']);

        $component = $this->bell($user)->set('open', true);

        $this->assertSame(['open' => true], $component->snapshot['data']);
        $this->assertStringNotContainsString('example.test', json_encode($component->snapshot['data']));
    }

    public function test_a_hidden_feed_renders_nothing_and_every_method_does_nothing(): void
    {
        $user = User::factory()->create();
        $notification = $this->notify($user, ['title' => 'Visible?', 'url' => '/x']);
        $this->app->bind(BellFeed::class, HiddenBellFeed::class);
        HiddenBellFeed::$calls = 0;

        $this->bell($user)
            ->set('open', true)
            ->assertDontSee('Notifications')
            ->assertDontSee('Visible?')
            ->call('openNotification', $notification->id)
            ->assertNoRedirect()
            ->call('markAsRead', $notification->id)
            ->call('markAllAsRead');

        $this->assertNull($notification->fresh()->read_at);
        $this->assertSame(0, HiddenBellFeed::$calls);
    }

    public function test_a_guest_sees_nothing_and_nothing_happens(): void
    {
        $notification = $this->notify(User::factory()->create());

        Livewire::test(NotificationBell::class)
            ->assertDontSee('Notifications')
            ->call('openNotification', $notification->id)
            ->assertNoRedirect()
            ->call('markAllAsRead');

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_a_user_without_notifications_support_gets_no_bell(): void
    {
        $user = new class extends \Illuminate\Foundation\Auth\User
        {
            protected $table = 'users';
        };
        $user->forceFill(['id' => 1]);

        $this->assertFalse(app(BellFeed::class)->visible($user));
        $this->assertSame([], app(BellFeed::class)->items($user, 5));
        $this->assertSame(0, app(BellFeed::class)->unreadCount($user));
        $this->assertNull(app(BellFeed::class)->find($user, (string) Str::uuid()));
    }

    public function test_the_default_layout_renders_the_bell_for_signed_in_users_when_enabled(): void
    {
        $user = User::factory()->create();
        $this->notify($user);

        $this->actingAs($user);
        $html = view('odden-livewire::layouts.partials.header-actions')->render();
        $this->assertStringContainsString('Notifications, 1 unread', $html);

        config(['odden-livewire.bell.enabled' => false]);
        $this->assertStringNotContainsString('Notifications', view('odden-livewire::layouts.partials.header-actions')->render());
    }

    public function test_the_partial_renders_nothing_for_guests(): void
    {
        $this->assertSame('', trim(view('odden-livewire::layouts.partials.header-actions')->render()));
    }
}
