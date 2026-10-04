<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Filament\Actions\AttachAction;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Odden\Core\Actions\AssociateRecordsAction;
use Odden\Core\Exceptions\CardinalityViolationException;
use Odden\Core\Exceptions\InvalidAssociationException;

/**
 * What the relation managers' Attach action does: it links the records through AssociateRecordsAction.
 *
 * Filament's own attach writes the pivot row directly, which skips everything the Association model and the action
 * do: the tenant guard and tenant column, record-type and cardinality rules, and the RecordsAssociated event.
 */
final class AttachesThroughAssociations
{
    /**
     * The attach form's record picker, without the DISTINCT Filament puts on it.
     *
     * Filament selects `DISTINCT table.*` so a record linked twice shows once, but PostgreSQL cannot compare json
     * columns (these tables have a `properties` column), so the picker failed there. Grouping on the primary key
     * does the same job on PostgreSQL, MySQL and SQLite.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function pickerQuery(Builder $query): Builder
    {
        $query->getQuery()->distinct = false;

        return $query->groupBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * @param  array<string, mixed>  $data  The attach form's state: "recordId" (one id or several) and an optional "type".
     * @param  bool  $ownerIsParent  Whether the record the manager is on is the association's parent (else its child).
     */
    public static function attach(AttachAction $action, Model $owner, Table $table, array $data, bool $ownerIsParent): void
    {
        $ids = array_values(array_filter((array) ($data['recordId'] ?? [])));
        $relationship = $table->getRelationship();

        if (! $relationship instanceof Relation) {
            $action->failure();

            return;
        }

        $related = $relationship->getRelated()->newQuery()->whereKey($ids)->get();
        $type = is_string($data['type'] ?? null) && $data['type'] !== '' ? $data['type'] : 'default';

        try {
            foreach ($related as $record) {
                $ownerIsParent
                    ? app(AssociateRecordsAction::class)->execute($owner, $record, $type)
                    : app(AssociateRecordsAction::class)->execute($record, $owner, $type);
            }
        } catch (CardinalityViolationException|InvalidAssociationException $e) {
            Notification::make()->danger()->title('Could not link these records')->body($e->getMessage())->send();
            $action->failure();

            return;
        }

        $action->success();
    }
}
