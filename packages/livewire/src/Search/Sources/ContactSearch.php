<?php

declare(strict_types=1);

namespace Odden\Livewire\Search\Sources;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\EncryptedSearch;
use Odden\Core\Models\Contact;

/**
 * @extends RecordSearch<Contact>
 */
final class ContactSearch extends RecordSearch
{
    public function label(): string
    {
        return 'Contacts';
    }

    public function screenKey(): string
    {
        return 'contacts.index';
    }

    protected function query(): Builder
    {
        return Contact::query();
    }

    protected function matcher(): Closure
    {
        return EncryptedSearch::contacts();
    }

    protected function viewScreenKey(): string
    {
        return 'contacts.view';
    }

    /**
     * @param  Contact  $record
     */
    protected function title(Model $record): string
    {
        return $record->full_name !== '' ? $record->full_name : (string) $record->email;
    }

    /**
     * @param  Contact  $record
     * @return list<string>
     */
    protected function details(Model $record): array
    {
        return array_filter([(string) $record->email]);
    }
}
