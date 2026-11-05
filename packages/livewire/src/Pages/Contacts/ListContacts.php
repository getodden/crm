<?php

declare(strict_types=1);

namespace Odden\Livewire\Pages\Contacts;

use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Odden\Core\Models\Contact;
use Odden\Core\Screens\Screens;
use Odden\Livewire\Concerns\AuthorizesScreen;

#[Layout('odden-livewire::layouts.app')]
#[Title('Contacts')]
final class ListContacts extends Component
{
    use AuthorizesScreen, WithPagination;

    public function render(): View
    {
        $linkable = Screens::available('contacts.view');

        $contacts = Contact::query()->orderByDesc('id')->paginate(25);

        $rows = $contacts->getCollection()->map(fn (Contact $contact): array => [
            'id' => $contact->getKey(),
            'name' => $contact->full_name,
            'email' => $contact->email,
            'url' => $linkable ? Screens::url('contacts.view', $contact) : null,
        ]);

        return view('odden-livewire::pages.contacts.list-contacts', ['contacts' => $contacts, 'rows' => $rows]);
    }

    protected function screenKey(): string
    {
        return 'contacts.index';
    }
}
