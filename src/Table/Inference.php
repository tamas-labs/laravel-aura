<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use TamasLabs\Aura\Contracts\AuraOption;
use TamasLabs\Aura\Support\Relations;

/**
 * Column defaults read out of the model.
 *
 * The model already knows most of what a column needs: a `decimal` cast says
 * money, a `datetime` cast says the search should be a range, an enum cast says
 * exactly which values the filter may offer. Restating that in the table
 * definition is duplication that goes stale — the cast changes and the column
 * quietly keeps formatting the old way.
 *
 * Everything here is written through {@see Column::default()}, so it only ever
 * fills a gap. Inference cannot override a decision, and `->withoutInference()`
 * switches it off for a column that is an exception.
 *
 * It is best-effort by design: a field it cannot resolve — a computed column, a
 * relation it cannot follow — simply gets no defaults. Defaults are cosmetic,
 * and guessing wrong is worse than not guessing.
 *
 * @internal
 */
final class Inference
{
    /**
     * Fill in what the model can tell us about this column.
     */
    public static function apply(Model $model, Column $column): void
    {
        if ($column->isSelectable() && $column->declaredField() === null) {
            // What Aura sends back in `selected` is the row id, so the selection
            // column reads the key the caller will look the rows up by.
            $column->default('field', $model->getKeyName());
        }

        $field = $column->declaredField();

        if ($field === null) {
            return;
        }

        $target = self::resolve($model, $field);

        if ($target === null) {
            return;
        }

        [$related, $attribute] = $target;

        $cast = $related->getCasts()[$attribute] ?? null;

        if (is_string($cast)) {
            self::fromCast($column, $cast);
        }
    }

    /**
     * The filter options a backed enum offers.
     *
     * The key is the case's backing value — what travels in the request and what
     * the database column holds — and the label is what the user reads. An enum
     * that has not implemented {@see AuraOption} still produces a usable list
     * from its case names; implementing the interface is how the wording, and
     * the translation, becomes yours.
     *
     * @param  class-string<BackedEnum>  $enum
     * @return array<string, string>
     */
    public static function elementsFrom(string $enum): array
    {
        $elements = [];

        foreach ($enum::cases() as $case) {
            $elements[(string) $case->value] = $case instanceof AuraOption
                ? $case->label()
                : Str::headline($case->name);
        }

        return $elements;
    }

    /**
     * Which model actually holds this field, and under what name.
     *
     * A dotted field is resolved one relation deep, which is what the header of
     * a table shows in practice (`company.name`). Anything deeper returns null
     * rather than guessing.
     *
     * @return array{0: Model, 1: string}|null
     */
    private static function resolve(Model $model, string $field): ?array
    {
        $position = strrpos($field, '.');

        if ($position === false) {
            return [$model, $field];
        }

        $path = substr($field, 0, $position);
        $attribute = substr($field, $position + 1);

        if (str_contains($path, '.')) {
            return null;
        }

        // Never calls a method that is not a relation — a field naming, say,
        // `delete.x` would otherwise run `$model->delete()` on the way to
        // finding that out. See {@see Relations}.
        $relation = Relations::on($model, $path);

        return $relation === null ? null : [$relation->getRelated(), $attribute];
    }

    /**
     * The cast-to-column-defaults table. Everything here is a default; nothing
     * here overrides.
     */
    private static function fromCast(Column $column, string $cast): void
    {
        if (is_subclass_of($cast, BackedEnum::class, true)) {
            $column->default('elements', self::elementsFrom($cast));

            return;
        }

        // Casts carry their format after a colon (`datetime:Y-m-d`, `decimal:2`).
        $base = str_contains($cast, ':') ? strstr($cast, ':', true) : $cast;

        match (is_string($base) ? $base : $cast) {
            'datetime', 'immutable_datetime', 'custom_datetime', 'timestamp' => self::temporal($column, 'datetime'),
            'date', 'immutable_date', 'immutable_custom_datetime' => self::temporal($column, 'date'),
            'decimal' => self::money($column),
            default => null,
        };
    }

    /**
     * A date or datetime column: formatted, and searched by range.
     *
     * The range only goes on a column that is searchable at all — `between`
     * without `searchable` is a flag Aura has nothing to attach to.
     */
    private static function temporal(Column $column, string $key): void
    {
        $column->default($key, true);

        if ($column->isSearchable()) {
            $column->default('between', true);
        }
    }

    /**
     * A `decimal` cast is money often enough to be the default, and
     * `->withoutInference()` is there for the times it is a weight.
     */
    private static function money(Column $column): void
    {
        $column->default('currency', true)->default('align', 'end');
    }
}
