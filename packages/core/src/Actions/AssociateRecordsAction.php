<?php

declare(strict_types=1);

namespace Odden\Core\Actions;

use Illuminate\Database\Eloquent\Model;
use Odden\Core\Enums\AssociationCardinality;
use Odden\Core\Events\RecordsAssociated;
use Odden\Core\Exceptions\CardinalityViolationException;
use Odden\Core\Exceptions\InvalidAssociationException;
use Odden\Core\Models\Association;
use Odden\Core\Models\AssociationType;

class AssociateRecordsAction
{
    /**
     * Associate two records together with an optional relationship type, label, and cardinality check.
     *
     * @throws CardinalityViolationException
     * @throws InvalidAssociationException
     */
    public function execute(
        Model $parent,
        Model $child,
        string|AssociationType $type = 'default',
        ?string $label = null
    ): Association {
        $associationType = null;
        $typeName = 'default';

        if ($type instanceof AssociationType) {
            $associationType = $type;
            $typeName = $associationType->name;
            $label ??= $associationType->label;
        } elseif (is_string($type)) {
            $typeName = $type;
            /** @var AssociationType|null $foundType */
            $foundType = AssociationType::where('name', $type)->first();
            $associationType = $foundType;
            if ($associationType !== null) {
                $label ??= $associationType->label;
            }
        }

        // Validate record types and cardinality constraints
        if ($associationType !== null) {
            $this->enforceRecordTypes($parent, $child, $associationType);
            $this->enforceCardinality($parent, $child, $associationType);
        }

        /** @var Association $association */
        $association = Association::firstOrNew([
            'parent_type' => $parent->getMorphClass(),
            'parent_id' => $parent->getKey(),
            'child_type' => $child->getMorphClass(),
            'child_id' => $child->getKey(),
            'type' => $typeName,
        ]);

        $isNew = ! $association->exists;

        if ($associationType !== null) {
            $association->association_type_id = $associationType->id;
        }

        if ($label !== null) {
            $association->label = $label;
        }

        $association->save();

        if ($isNew) {
            event(new RecordsAssociated($association));
        }

        return $association;
    }

    /**
     * Enforce the record types an association type allows. The two records must be the type's
     * `from_record_type` and `to_record_type`, in either order; when only one is set, one of the
     * two records must be of that type.
     *
     * @throws InvalidAssociationException
     */
    protected function enforceRecordTypes(Model $parent, Model $child, AssociationType $type): void
    {
        $from = $type->from_record_type;
        $to = $type->to_record_type;

        if ($from === null && $to === null) {
            return;
        }

        $records = [$parent->getMorphClass(), $child->getMorphClass()];

        $allowed = match (true) {
            $from !== null && $to !== null => ($records[0] === $from && $records[1] === $to) || ($records[0] === $to && $records[1] === $from),
            $from !== null => in_array($from, $records, true),
            default => in_array($to, $records, true),
        };

        if (! $allowed) {
            $expected = $from !== null && $to !== null ? "{$from} and {$to}" : ($from ?? $to);

            throw new InvalidAssociationException("Association type [{$type->name}] links {$expected} records, not {$records[0]} and {$records[1]}.");
        }
    }

    /**
     * Enforce cardinality rules for association types.
     */
    protected function enforceCardinality(Model $parent, Model $child, AssociationType $type): void
    {
        if ($type->cardinality === AssociationCardinality::OneToOne) {
            // Check if parent is already linked to another child of this type
            $parentLinked = Association::query()
                ->where('association_type_id', $type->id)
                ->where('parent_type', $parent->getMorphClass())
                ->where('parent_id', $parent->getKey())
                ->where(function ($q) use ($child): void {
                    $q->where('child_type', '!=', $child->getMorphClass())
                        ->orWhere('child_id', '!=', $child->getKey());
                })
                ->exists();

            if ($parentLinked) {
                throw new CardinalityViolationException("Association type [{$type->name}] has One-to-One cardinality and parent record already has an association.");
            }

            // Check if child is already linked to another parent of this type
            $childLinked = Association::query()
                ->where('association_type_id', $type->id)
                ->where('child_type', $child->getMorphClass())
                ->where('child_id', $child->getKey())
                ->where(function ($q) use ($parent): void {
                    $q->where('parent_type', '!=', $parent->getMorphClass())
                        ->orWhere('parent_id', '!=', $parent->getKey());
                })
                ->exists();

            if ($childLinked) {
                throw new CardinalityViolationException("Association type [{$type->name}] has One-to-One cardinality and child record is already associated with another record.");
            }
        } elseif ($type->cardinality === AssociationCardinality::OneToMany) {
            // In One-to-Many: a child can only belong to at most one parent
            $childLinked = Association::query()
                ->where('association_type_id', $type->id)
                ->where('child_type', $child->getMorphClass())
                ->where('child_id', $child->getKey())
                ->where(function ($q) use ($parent): void {
                    $q->where('parent_type', '!=', $parent->getMorphClass())
                        ->orWhere('parent_id', '!=', $parent->getKey());
                })
                ->exists();

            if ($childLinked) {
                throw new CardinalityViolationException("Association type [{$type->name}] has One-to-Many cardinality and child record already belongs to a parent.");
            }
        }
    }
}
