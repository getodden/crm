<?php

declare(strict_types=1);

namespace Odden\Livewire\Search\Sources;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Encryption\EncryptedSearch;
use Odden\Core\Models\Company;

/**
 * @extends RecordSearch<Company>
 */
final class CompanySearch extends RecordSearch
{
    public function label(): string
    {
        return 'Companies';
    }

    public function screenKey(): string
    {
        return 'companies.index';
    }

    protected function query(): Builder
    {
        return Company::query();
    }

    protected function matcher(): Closure
    {
        return EncryptedSearch::companies();
    }

    protected function viewScreenKey(): string
    {
        return 'companies.view';
    }

    /**
     * @param  Company  $record
     */
    protected function title(Model $record): string
    {
        return (string) $record->name;
    }

    /**
     * @param  Company  $record
     * @return list<string>
     */
    protected function details(Model $record): array
    {
        return array_filter([(string) $record->domain]);
    }
}
