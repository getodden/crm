<div>
    @if ($visible)
        <div class="relative" wire:poll.30s.visible>
            <x-ui::button
                variant="ghost"
                icon="fas-bell"
                :label="$unread > 0 ? __('Notifications, :count unread', ['count' => $unread]) : __('Notifications')"
                x-on:click="$dispatch('ui-modal-open', { name: 'odden-notifications' })"
            />
            @if ($unread > 0)
                <span aria-hidden="true" class="pointer-events-none absolute -end-1 -top-1">
                    <x-ui::badge variant="danger">{{ $unread > 99 ? '99+' : $unread }}</x-ui::badge>
                </span>
            @endif
        </div>

        <x-ui::modal name="odden-notifications" :heading="__('Notifications')" size="md" wire:model.live="open">
            @if ($unread > 0)
                <div class="mb-3 flex justify-end">
                    <x-ui::button variant="ghost" size="sm" wire:click="markAllAsRead">{{ __('Mark all as read') }}</x-ui::button>
                </div>
            @endif

            @if ($items === [])
                <p class="text-sm text-gray-600 dark:text-gray-400">{{ __('No notifications') }}</p>
            @else
                <ul class="divide-y divide-gray-200 dark:divide-gray-800">
                    @foreach ($items as $item)
                        <li wire:key="bell-{{ $item->id }}" class="py-3">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-sm {{ $item->read ? 'text-gray-700 dark:text-gray-300' : 'font-semibold text-gray-900 dark:text-gray-100' }}">
                                        @unless ($item->read)
                                            <span class="sr-only">{{ __('Unread') }}: </span>
                                        @endunless
                                        {{ $item->title }}
                                    </p>
                                    @if ($item->body !== null)
                                        <p class="mt-0.5 text-sm text-gray-600 dark:text-gray-400">{{ $item->body }}</p>
                                    @endif
                                    <p class="mt-1 text-xs text-gray-600 dark:text-gray-400">
                                        <time datetime="{{ $item->createdAt->toIso8601String() }}">{{ $item->createdAt->diffForHumans() }}</time>
                                    </p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-1">
                                    @if ($item->url !== null)
                                        <a
                                            href="{{ $item->url }}"
                                            wire:click.prevent="openNotification({{ \Illuminate\Support\Js::from($item->id) }})"
                                            class="text-sm font-medium text-primary-700 hover:underline dark:text-primary-400"
                                        >{{ $item->actionLabel ?? __('Open') }}</a>
                                    @endif
                                    @unless ($item->read)
                                        <button
                                            type="button"
                                            wire:click="markAsRead({{ \Illuminate\Support\Js::from($item->id) }})"
                                            class="text-sm text-gray-700 hover:underline dark:text-gray-300"
                                        >{{ __('Mark as read') }}</button>
                                    @endunless
                                </div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-ui::modal>
    @endif
</div>
