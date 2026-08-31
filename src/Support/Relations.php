<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Resolves the relation a dotted field names — without calling anything that
 * is not one.
 *
 * A dotted field such as `company.name` names a method on the model, and the
 * obvious guard is `method_exists()`. It is not enough: `Column::make('delete.x')`
 * passes it, and the header build then *calls* `$model->delete()` before
 * discovering that what came back is not a relation.
 *
 * Laravel's own `Model::isRelation()` is no help here — it is
 * `method_exists() || relationResolver()` and says nothing about what the
 * method returns. So the method is inspected before it is called:
 *
 * - it has to be public, non-static, and callable with no arguments;
 * - it must not be **declared by the framework**. `delete()`, `save()`,
 *   `push()`, `refresh()` and about a hundred more are untyped `Model`
 *   methods, so the declaring class is the only thing separating them from an
 *   equally untyped relation method on the caller's own model;
 * - if it declares a return type, that type has to be a `Relation`.
 *
 * The middle rule is what carries the weight. Requiring a declared `Relation`
 * return type would be tighter and simpler, but relation methods have carried
 * nothing but a `@return` docblock for most of Laravel's life, and refusing to
 * infer through every one of those would cost far more than this is worth.
 *
 * What stays reachable is an untyped, side-effecting method on the caller's
 * *own* model, named by a column — a typo in the table definition, never
 * anything a client can send. A dynamic relation registered with
 * `Model::resolveRelationUsing()` is not reachable either, and never was:
 * it is not a real method, so `method_exists()` has always said no.
 *
 * @internal
 */
final class Relations
{
    /**
     * The relation this name declares on the model, or `null` when the name is
     * not a relation — including when it names a method that must not be run.
     *
     * @return Relation<covariant Model, covariant Model, mixed>|null
     */
    public static function on(Model $model, string $name): ?Relation
    {
        if (! self::isSafeToCall($model, $name)) {
            return null;
        }

        $relation = $model->{$name}();

        return $relation instanceof Relation ? $relation : null;
    }

    /**
     * Everything that can be known about the method without running it.
     */
    private static function isSafeToCall(Model $model, string $name): bool
    {
        if (! method_exists($model, $name)) {
            return false;
        }

        $method = new ReflectionMethod($model, $name);

        if (! $method->isPublic() || $method->isStatic() || $method->getNumberOfRequiredParameters() > 0) {
            return false;
        }

        // An `Attribute` accessor is protected and so is already excluded here,
        // which is the same conclusion Laravel reaches via `hasAttributeMutator`.
        if (str_starts_with($method->getDeclaringClass()->getName(), 'Illuminate\\')) {
            return false;
        }

        $type = $method->getReturnType();

        if ($type === null) {
            return true;
        }

        return $type instanceof ReflectionNamedType
            && ! $type->isBuiltin()
            && is_a($type->getName(), Relation::class, true);
    }
}
