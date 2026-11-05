<?php

declare(strict_types=1);

namespace Odden\Livewire\Search\Sources;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Screens\Screens;
use Odden\Livewire\Contracts\SearchSource;
use Odden\Livewire\Search\SearchHit;

/**
 * @template TModel of Model
 */
abstract class RecordSearch implements SearchSource
{
    /**
     * @return Builder<TModel>
     */
    abstract protected function query(): Builder;

    /**
     * @return Closure(Builder<TModel>, string): Builder<TModel>
     */
    abstract protected function matcher(): Closure;

    abstract protected function viewScreenKey(): string;

    /**
     * @param  TModel  $record
     */
    abstract protected function title(Model $record): string;

    /**
     * @param  TModel  $record
     * @return list<string>
     */
    abstract protected function details(Model $record): array;

    public function search(string $term, Authenticatable $user, int $limit): array
    {
        $query = $this->query();

        $records = ($this->matcher())($query, $term)
            ->orderBy($query->getModel()->getQualifiedKeyName())
            ->limit($limit)
            ->get();

        $hits = [];

        foreach ($records as $record) {
            if (! Screens::canOpen($this->viewScreenKey(), $user, $record)) {
                continue;
            }

            $hits[] = new SearchHit($this->title($record), Screens::url($this->viewScreenKey(), $record), $this->details($record));
        }

        return $hits;
    }
}
