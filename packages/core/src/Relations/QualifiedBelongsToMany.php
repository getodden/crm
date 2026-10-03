<?php

declare(strict_types=1);

namespace Odden\Core\Relations;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;

/**
 * A many-to-many relation that qualifies plain column names with the related table when plucking.
 *
 * The association table has its own `id`, so `$contact->companies()->pluck('id')` would otherwise
 * fail with an ambiguous column error.
 *
 * @extends BelongsToMany<Model, Model>
 */
class QualifiedBelongsToMany extends BelongsToMany
{
    /**
     * @param  Expression|string  $column
     * @param  string|null  $key
     * @return Collection<array-key, mixed>
     */
    public function pluck($column, $key = null)
    {
        return parent::pluck($this->qualifyPluckColumn($column), $this->qualifyPluckColumn($key));
    }

    protected function qualifyPluckColumn(mixed $column): mixed
    {
        if (is_string($column) && ! str_contains($column, '.') && stripos($column, ' as ') === false) {
            return $this->related->qualifyColumn($column);
        }

        return $column;
    }
}
