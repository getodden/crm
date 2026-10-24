<?php

declare(strict_types=1);

namespace Odden\Filament\Support;

use Filament\Forms\Components\Field;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Model;
use Odden\Core\Areas\Areas;
use Odden\Core\Areas\DataArea;
use Odden\Core\Areas\DataAreas;
use Odden\Core\Encryption\SensitiveProperties;

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
     * Whether the person cannot see a custom property the workspace marked sensitive (on this record, when one is open).
     */
    public static function propertyMasked(string $entityType, string $property, ?Model $record = null): bool
    {
        $area = app(SensitiveProperties::class)->areaOf($entityType, $property);

        return $area !== null && ! app(Areas::class)->canSee($area, $record?->exists ? $record : null);
    }

    /**
     * A form field for a custom property: when the property is sensitive and the person cannot see its area, the field is disabled,
     * empty and never saved. A sensitive property that they can see says so in its hint.
     *
     * @template TField of Field
     *
     * @param  TField  $field
     * @return TField
     */
    public static function property(Field $field, string $entityType, string $property): Field
    {
        $area = app(SensitiveProperties::class)->areaOf($entityType, $property);

        if ($area === null) {
            return $field;
        }

        $masked = static fn (?Model $record): bool => self::propertyMasked($entityType, $property, $record);

        return $field
            ->disabled($masked)
            ->dehydrated(static fn (?Model $record): bool => ! $masked($record))
            ->hint(static fn (?Model $record): string => $masked($record) ? 'Hidden: outside your areas' : "Sensitive · {$area->label()} area · stored encrypted");
    }

    /**
     * Whether the person lacks the area and would get nothing from a search that reaches into it.
     */
    public static function lacks(DataArea $area): bool
    {
        return ! app(Areas::class)->holds($area);
    }
}
