<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Filament\Forms\Components\Field;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Areas\DataAreas;

/**
 * What the panel shows of a field whose data area the signed-in person does not hold (PLAN-encryption-at-rest.md, section 5): a form
 * field is disabled, empty and never saved; a table column shows a mask, and does not search. Without areas configured every person
 * holds every area and nothing here changes anything.
 */
final class AreaGuard
{
    /**
     * Whether the person cannot see this attribute of the model (on this record, when one is open: a reason opens an area for one record).
     *
     * @param  class-string<Model>  $model
     */
    public static function masked(string $model, string $attribute, ?Model $record = null): bool
    {
        $areas = app(DataAreas::class);
        $area = $areas->areaOfAttribute($record ?? new $model, $attribute);

        return $area !== null && ! app(Areas::class)->canSee($area, $record?->exists ? $record : null);
    }

    /**
     * A form field for an encrypted attribute: disabled, left out of the saved data and shown empty when it is masked.
     *
     * @template TField of Field
     *
     * @param  TField  $field
     * @param  class-string<Model>  $model
     * @return TField
     */
    public static function field(Field $field, string $model): Field
    {
        $attribute = $field->getName();
        $masked = static fn (?Model $record): bool => self::masked($model, $attribute, $record);

        $field
            ->disabled($masked)
            ->dehydrated(static fn (?Model $record): bool => ! $masked($record))
            ->hint(static fn (?Model $record): ?string => $masked($record) ? 'Hidden: outside your areas' : null);

        return $field;
    }

    /**
     * A table column for an encrypted attribute: a mask instead of the value.
     *
     * @param  class-string<Model>  $model
     */
    public static function column(TextColumn $column, string $model): TextColumn
    {
        $attribute = $column->getName();

        return $column->formatStateUsing(static fn (mixed $state, ?Model $record): mixed => self::masked($model, $attribute, $record) ? Areas::MASK : $state);
    }

    /**
     * Whether the person lacks the area and would get nothing from a search that reaches into it.
     */
    public static function lacks(DataArea $area): bool
    {
        return ! app(Areas::class)->holds($area);
    }
}
