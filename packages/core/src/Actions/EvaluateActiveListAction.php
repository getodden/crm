<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Odden\Core\Models\Company;
use Odden\Core\Models\Contact;
use Odden\Core\Models\CrmList;
use Odden\Core\Models\ListMembership;

class EvaluateActiveListAction
{
    /**
     * Evaluate filter criteria for an active list and sync its memberships.
     */
    public function execute(CrmList $list): int
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = match ($list->entity_type) {
            'company' => Company::class,
            default => Contact::class,
        };

        $modelInstance = new $modelClass;
        $tableColumns = Schema::getColumnListing($modelInstance->getTable());

        $query = $modelClass::query();
        $rules = $list->criteria ?? [];

        foreach ($rules as $rule) {
            $property = (string) ($rule['property'] ?? '');
            $operator = (string) ($rule['operator'] ?? '=');
            $value = $rule['value'] ?? null;

            if ($property === '') {
                continue;
            }

            // Marketing behavioral cohort criteria
            if ($property === 'has_downloaded_asset' && $modelClass === Contact::class) {
                $contactsTable = (new Contact)->getTable();
                $table = config('odden-marketing.tables.asset_downloads', 'odden_marketing_asset_downloads');
                if ((bool) $value) {
                    $query->whereExists(function ($sub) use ($table, $contactsTable): void {
                        $sub->selectRaw(1)
                            ->from($table)
                            ->whereColumn('contact_id', "{$contactsTable}.id");
                    });
                } else {
                    $query->whereNotExists(function ($sub) use ($table, $contactsTable): void {
                        $sub->selectRaw(1)
                            ->from($table)
                            ->whereColumn('contact_id', "{$contactsTable}.id");
                    });
                }

                continue;
            }

            if ($property === 'has_attended_event' && $modelClass === Contact::class) {
                $contactsTable = (new Contact)->getTable();
                $table = config('odden-marketing.tables.event_registrations', 'odden_marketing_event_registrations');
                if ((bool) $value) {
                    $query->whereExists(function ($sub) use ($table, $contactsTable): void {
                        $sub->selectRaw(1)
                            ->from($table)
                            ->whereColumn('contact_id', "{$contactsTable}.id")
                            ->where('status', 'attended');
                    });
                } else {
                    $query->whereNotExists(function ($sub) use ($table, $contactsTable): void {
                        $sub->selectRaw(1)
                            ->from($table)
                            ->whereColumn('contact_id', "{$contactsTable}.id")
                            ->where('status', 'attended');
                    });
                }

                continue;
            }

            // Determine if column exists on model table or is a dynamic custom property
            $isTableColumn = in_array($property, $tableColumns, true);
            $hasPropertiesJson = in_array('properties', $tableColumns, true);

            if ($isTableColumn && $hasPropertiesJson && $property !== 'properties') {
                $query->where(function (Builder $sub) use ($property, $operator, $value): void {
                    $this->applyRuleToQuery($sub, $property, $operator, $value);
                    $sub->orWhere(function (Builder $propSub) use ($property, $operator, $value): void {
                        $this->applyRuleToQuery($propSub, "properties->{$property}", $operator, $value);
                    });
                });
            } elseif ($isTableColumn) {
                $this->applyRuleToQuery($query, $property, $operator, $value);
            } else {
                $this->applyRuleToQuery($query, "properties->{$property}", $operator, $value);
            }
        }

        /** @var list<int> $matchingIds */
        $matchingIds = $query->pluck($modelInstance->getKeyName())->map(fn ($id): int => (int) $id)->all();

        // 1. Remove members that no longer match criteria
        ListMembership::query()
            ->where('list_id', $list->id)
            ->where('member_type', $modelInstance->getMorphClass())
            ->whereNotIn('member_id', $matchingIds)
            ->delete();

        // 2. Add newly matching members
        $existingMemberIds = ListMembership::query()
            ->where('list_id', $list->id)
            ->where('member_type', $modelInstance->getMorphClass())
            ->pluck('member_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $newIds = array_diff($matchingIds, $existingMemberIds);

        foreach ($newIds as $newId) {
            ListMembership::create([
                'list_id' => $list->id,
                'member_type' => $modelInstance->getMorphClass(),
                'member_id' => $newId,
                'added_at' => now(),
            ]);
        }

        return count($matchingIds);
    }

    /**
     * Apply an individual filter rule to an Eloquent query.
     *
     * @param  Builder<Model>  $query
     */
    protected function applyRuleToQuery(Builder $query, string $column, string $operator, mixed $value): void
    {
        match ($operator) {
            '!=' => $query->where($column, '!=', $value),
            '>' => $query->where($column, '>', $value),
            '>=' => $query->where($column, '>=', $value),
            '<' => $query->where($column, '<', $value),
            '<=' => $query->where($column, '<=', $value),
            'contains' => $query->where($column, 'LIKE', '%'.$value.'%'),
            'in' => $query->whereIn($column, (array) $value),
            'not_in' => $query->whereNotIn($column, (array) $value),
            'is_null' => $query->whereNull($column),
            'is_not_null' => $query->whereNotNull($column),
            default => $query->where($column, '=', $value),
        };
    }
}
