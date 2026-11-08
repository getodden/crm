<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Odden\Core\Support\TenantGuard;

class SyncChildRecordsAction
{
    /**
     * @template TChild of Model
     * @template TParent of Model
     *
     * @param  HasMany<TChild, TParent>  $relation
     * @param  list<array<string, mixed>>  $rows
     * @param  list<int|string>|null  $loadedIds
     */
    public function execute(HasMany $relation, array $rows, ?string $orderColumn = null, ?array $loadedIds = null): void
    {
        app(TenantGuard::class)->assertOwnedWhenTenanted($relation->getParent());

        DB::transaction(function () use ($relation, $rows, $orderColumn, $loadedIds): void {
            $existing = $relation->get()->keyBy(fn (Model $child): string => (string) $child->getKey());
            $kept = [];

            foreach ($rows as $position => $row) {
                $id = isset($row['id']) ? (string) $row['id'] : null;
                unset($row['id']);

                if ($orderColumn !== null) {
                    $row[$orderColumn] = $position + 1;
                }

                $child = $id !== null ? $existing->get($id) : null;

                if ($child === null) {
                    $relation->create($row);

                    continue;
                }

                $child->fill($row)->save();
                $kept[] = $id;
            }

            $removable = $loadedIds === null
                ? $existing
                : $existing->only(array_map('strval', $loadedIds));

            $removable->except($kept)->each(fn (Model $child): ?bool => $child->delete());
        });
    }
}
