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
use Odden\Core\Actions\AttachRelatedRecordsAction;
use Odden\Core\Exceptions\CardinalityViolationException;
use Odden\Core\Exceptions\CrossTenantException;
use Odden\Core\Exceptions\InvalidAssociationException;

final class AttachesThroughAssociations
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function pickerQuery(Builder $query, ?string $search = null, ?Closure $searchUsing = null): Builder
    {
        $model = $query->getModel();
        $key = $model->getQualifiedKeyName();

        $query->getQuery()->distinct = false;

        $keys = OddenAuthorization::constrain($query)->reorder()->select($key)->distinct();

        $picker = $model->newQuery()->whereIn($key, $keys->toBase());

        if ($searchUsing !== null && filled($search)) {
            $searchUsing($picker, $search);
        }

        return $picker;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function attach(AttachAction $action, Model $owner, Table $table, array $data, bool $ownerIsParent): void
    {
        $ids = array_values(array_filter((array) ($data['recordId'] ?? [])));
        $relationship = $table->getRelationship();

        if (! $relationship instanceof Relation) {
            $action->failure();

            return;
        }

        $related = OddenAuthorization::constrain($relationship->getRelated()->newQuery())->whereKey($ids)->get();
        if ($related->count() !== count(array_unique($ids))) {
            Notification::make()->danger()->title('Could not link these records')->body('One or more of the selected records could not be found.')->send();
            $action->failure();

            return;
        }

        $type = is_string($data['type'] ?? null) && $data['type'] !== '' ? $data['type'] : 'default';

        try {
            app(AttachRelatedRecordsAction::class)->execute($owner, $related, $type, $ownerIsParent);
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
