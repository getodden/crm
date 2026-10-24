<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Closure;
use Filament\Actions\AttachAction;
use Filament\Notifications\Notification;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Odden\Core\Actions\AssociateRecordsAction;
use Odden\Core\Exceptions\CardinalityViolationException;
use Odden\Core\Exceptions\CrossTenantException;
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
     * fixed that on PostgreSQL, MySQL and SQLite, but MariaDB (and MySQL before 5.7.5) does not know the other
     * columns follow from the key and refuses `select table.* ... group by table.id`.
     *
     * So the picker asks for the keys only (DISTINCT over a key is valid everywhere) and offers the records with
     * those keys. Filament adds its search and ordering to the query this returns, on the model's own table; a picker whose title
     * column is encrypted gives `$searchUsing` instead and leaves the record title attribute off the table (Filament would LIKE it).
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function pickerQuery(Builder $query, ?string $search = null, ?Closure $searchUsing = null): Builder
    {
        $model = $query->getModel();
        $key = $model->getQualifiedKeyName();

        $query->getQuery()->distinct = false;

        // Offer only the records the user can see. The ordering is Filament's for the outer query: DISTINCT over the key
        // cannot be ordered by a column that is not selected.
        $keys = OddenAuthorization::constrain($query)->reorder()->select($key)->distinct();

        $picker = $model->newQuery()->whereIn($key, $keys->toBase());

        // Encrypted columns cannot be searched with LIKE, so a picker over them searches through the model's own search.
        if ($searchUsing !== null && filled($search)) {
            $searchUsing($picker, $search);
        }

        return $picker;
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

        // Only records the user can see can be linked, whatever ids the request carries.
        $related = OddenAuthorization::constrain($relationship->getRelated()->newQuery())->whereKey($ids)->get();
        if ($related->count() !== count(array_unique($ids))) {
            Notification::make()->danger()->title('Could not link these records')->body('One or more of the selected records could not be found.')->send();
            $action->failure();

            return;
        }

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
        } catch (CrossTenantException) {
            Notification::make()->danger()->title('Could not link these records')->body('These records belong to different teams.')->send();
            $action->failure();

            return;
        }

        $action->success();
    }
}
