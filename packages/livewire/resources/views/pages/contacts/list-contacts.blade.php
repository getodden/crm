<div>
    <x-ui::card heading="Contacts">
        @if ($rows->isEmpty())
            <p class="text-sm text-gray-600 dark:text-gray-400">There are no contacts yet.</p>
        @else
            <table class="w-full text-left text-sm">
                <caption class="sr-only">Contacts, newest first</caption>
                <thead>
                    <tr class="border-b border-gray-200 dark:border-gray-700">
                        <th scope="col" class="py-2 pe-4 font-semibold text-gray-900 dark:text-gray-100">Name</th>
                        <th scope="col" class="py-2 font-semibold text-gray-900 dark:text-gray-100">Email</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="contact-{{ $row['id'] }}" class="border-b border-gray-100 dark:border-gray-800">
                            <td class="py-2 pe-4">
                                @if ($row['url'])
                                    <a href="{{ $row['url'] }}" class="font-medium text-primary-700 underline dark:text-primary-400">{{ $row['name'] }}</a>
                                @else
                                    {{ $row['name'] }}
                                @endif
                            </td>
                            <td class="py-2 text-gray-700 dark:text-gray-300">{{ $row['email'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-4">
                {{ $contacts->links() }}
            </div>
        @endif
    </x-ui::card>
</div>
