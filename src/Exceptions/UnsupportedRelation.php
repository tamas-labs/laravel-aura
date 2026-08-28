<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Exceptions;

use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;

/**
 * Raised when a dotted field cannot be sorted through the relation it names.
 */
final class UnsupportedRelation extends RuntimeException implements AuraException
{
    /**
     * Sorting resolves a to-one relation into a correlated subquery, which only
     * has a single answer for `BelongsTo` and `HasOne`.
     *
     * @param  Relation<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model, mixed>  $relation
     */
    public static function forSort(string $field, Relation $relation): self
    {
        return new self(sprintf(
            'Cannot sort by "%s": %s is a %s, and a to-many relation has no single value to order on. '
            .'Expose the value as a real column (a counter cache or a computed column) and sort on that.',
            $field,
            $field,
            class_basename($relation),
        ));
    }

    /**
     * Nested relation paths would need one correlated subquery per level.
     */
    public static function forNestedSort(string $field): self
    {
        return new self(sprintf(
            'Cannot sort by "%s": only a single relation level is supported on the sort side. '
            .'Search and filter accept any depth.',
            $field,
        ));
    }
}
