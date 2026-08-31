<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Query;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Expression;
use TamasLabs\Aura\Exceptions\UnsupportedRelation;
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Request\Filter;
use TamasLabs\Aura\Request\Search;
use TamasLabs\Aura\Request\Sort;
use TamasLabs\Aura\Support\Relations;

/**
 * Turns a validated {@see AuraRequest} into constraints on an Eloquent query.
 *
 * Every field reaching this class has already passed the {@see FieldPermissions}
 * whitelist, so nothing here re-checks authorisation — but nothing here accepts a
 * field from anywhere else either.
 *
 * Dotted fields resolve through relations: searching and filtering use
 * `whereHas` and work at any depth, while sorting uses a correlated subquery and
 * is limited to one to-one level (see {@see self::toOneSubquery()}).
 */
final class AuraQuery
{
    /**
     * Escape character for `LIKE` patterns.
     *
     * Not a backslash: MySQL and SQLite disagree on whether a backslash inside a
     * string literal is itself an escape, so `ESCAPE '\\'` means different things
     * on each. `!` is one character in every dialect.
     */
    private const LIKE_ESCAPE = '!';

    /**
     * Apply sorting, searching, filtering and global search — in that order of
     * declaration, though only ordering is order-sensitive.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, AuraRequest $request): Builder
    {
        self::applySearches($query, $request->searchable);
        self::applyFilters($query, $request->filterable);
        self::applyGlobalSearch($query, $request);
        self::applySorts($query, $request->sortable);

        return $query;
    }

    /**
     * Apply the request and paginate with it.
     *
     * `paginate()` on purpose: the response contract requires `meta.last_page`
     * and `meta.total`, which only a length-aware paginator knows.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return LengthAwarePaginator<int, TModel>
     */
    public static function paginate(Builder $query, AuraRequest $request): LengthAwarePaginator
    {
        return self::apply($query, $request)->paginate(
            perPage: $request->paginate,
            page: $request->page,
        );
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<Search>  $searches
     */
    private static function applySearches(Builder $query, array $searches): void
    {
        foreach ($searches as $search) {
            [$relation, $column] = self::split($search->field);

            if ($relation === null) {
                self::searchIn($query, $search, $column);

                continue;
            }

            $query->whereHas($relation, static function (Builder $related) use ($search, $column): void {
                self::searchIn($related, $search, $column);
            });
        }
    }

    /**
     * One column search: either a range, or a term with or without `exact`.
     *
     * @template TRelated of Model
     *
     * @param  Builder<TRelated>  $query
     */
    private static function searchIn(Builder $query, Search $search, string $column): void
    {
        $qualified = $query->getModel()->qualifyColumn($column);

        if ($search->isRange()) {
            // Either end may be null, which means unbounded rather than "match null".
            if ($search->min !== null) {
                $query->where($qualified, '>=', $search->min);
            }

            if ($search->max !== null) {
                $query->where($qualified, '<=', $search->max);
            }

            return;
        }

        if ($search->term === null || $search->term === '') {
            return;
        }

        if ($search->exact) {
            $query->where($qualified, '=', $search->term);

            return;
        }

        self::whereLike($query, $qualified, $search->term);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<Filter>  $filters
     */
    private static function applyFilters(Builder $query, array $filters): void
    {
        foreach ($filters as $filter) {
            [$relation, $column] = self::split($filter->field);

            if ($relation === null) {
                self::filterIn($query, $filter, $column);

                continue;
            }

            $query->whereHas($relation, static function (Builder $related) use ($filter, $column): void {
                self::filterIn($related, $filter, $column);
            });
        }
    }

    /**
     * @template TRelated of Model
     *
     * @param  Builder<TRelated>  $query
     */
    private static function filterIn(Builder $query, Filter $filter, string $column): void
    {
        $qualified = $query->getModel()->qualifyColumn($column);

        $withoutNull = array_values(array_filter(
            $filter->values,
            static fn (mixed $value): bool => $value !== null,
        ));

        // `IN (…)` never matches NULL, so a selected "no value" has to be asked
        // for separately or it silently drops those rows.
        if (count($withoutNull) === count($filter->values)) {
            $query->whereIn($qualified, $filter->values);

            return;
        }

        $query->where(static function (Builder $group) use ($qualified, $withoutNull): void {
            $group->whereIn($qualified, $withoutNull)->orWhereNull($qualified);
        });
    }

    /**
     * The toolbar's global search, over the fields the table declared for it.
     *
     * Grouped in its own nested `where` so the ORs cannot escape and widen the
     * per-column constraints around them.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function applyGlobalSearch(Builder $query, AuraRequest $request): void
    {
        $term = $request->globalSearch;
        $fields = $request->fields->globalSearch;

        if ($term === null || $term === '' || $fields === []) {
            return;
        }

        $query->where(static function (Builder $group) use ($fields, $term): void {
            foreach ($fields as $field) {
                [$relation, $column] = self::split($field);

                if ($relation === null) {
                    self::orWhereLike($group, $group->getModel()->qualifyColumn($column), $term);

                    continue;
                }

                $group->orWhereHas($relation, static function (Builder $related) use ($column, $term): void {
                    self::whereLike($related, $related->getModel()->qualifyColumn($column), $term);
                });
            }
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  list<Sort>  $sorts
     */
    private static function applySorts(Builder $query, array $sorts): void
    {
        foreach ($sorts as $sort) {
            [$relation, $column] = self::split($sort->field);

            if ($relation === null) {
                $query->orderBy($query->getModel()->qualifyColumn($column), $sort->direction);

                continue;
            }

            $query->orderBy(self::toOneSubquery($query, $sort->field, $relation, $column), $sort->direction);
        }
    }

    /**
     * A correlated subquery yielding the related column to order on.
     *
     * A join would read more naturally, but it multiplies rows on a to-many
     * relation, which corrupts `meta.total` and the page contents. A subquery
     * has no such effect and needs no select-list surgery — the cost is that it
     * only answers for a to-one relation, one level deep.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<Model>
     */
    private static function toOneSubquery(Builder $query, string $field, string $path, string $column): Builder
    {
        if (str_contains($path, '.')) {
            throw UnsupportedRelation::forNestedSort($field);
        }

        // Inspected before it is called: a whitelisted field is the table
        // author's, not the client's, but a typo naming a real method would
        // otherwise run it. See {@see Relations}.
        $relation = Relations::on($query->getModel(), $path);

        if ($relation === null) {
            throw UnsupportedRelation::notARelation($field, $path);
        }

        $related = $relation->getRelated();

        /** @var Builder<Model> $subquery */
        $subquery = $related->newQuery()
            ->select($related->qualifyColumn($column))
            ->limit(1);

        return match (true) {
            $relation instanceof BelongsTo => $subquery->whereColumn(
                $relation->getQualifiedOwnerKeyName(),
                $relation->getQualifiedForeignKeyName(),
            ),
            $relation instanceof HasOne => $subquery->whereColumn(
                $relation->getQualifiedForeignKeyName(),
                $relation->getQualifiedParentKeyName(),
            ),
            default => throw UnsupportedRelation::forSort($field, $relation),
        };
    }

    /**
     * A substring match with the wildcards in the user's term neutralised.
     *
     * Without this a term of `%` matches every row — not an injection (the term
     * is still bound), but a search box that quietly turns into a full scan.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function whereLike(Builder $query, string $column, string $term): void
    {
        [$sql, $bindings] = self::likeClause($query, $column, $term);

        $query->whereRaw($sql, $bindings);
    }

    /**
     * The same match, OR'd into the surrounding group.
     *
     * Wrapped in a nested closure rather than `orWhereRaw`, which only accepts a
     * literal string — the clause below is assembled from the grammar's own
     * column wrapping and so is not one.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     */
    private static function orWhereLike(Builder $query, string $column, string $term): void
    {
        $query->orWhere(static function (Builder $nested) use ($column, $term): void {
            self::whereLike($nested, $column, $term);
        });
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return array{0: Expression<literal-string>, 1: list<string>}
     */
    private static function likeClause(Builder $query, string $column, string $term): array
    {
        $escaped = str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE.self::LIKE_ESCAPE, self::LIKE_ESCAPE.'%', self::LIKE_ESCAPE.'_'],
            $term,
        );

        return [
            self::likeExpression($query->getQuery()->getGrammar()->wrap($column)),
            ['%'.$escaped.'%'],
        ];
    }

    /**
     * The one piece of raw SQL in this package.
     *
     * The query grammar cannot express an `ESCAPE` clause, and without one the
     * `%` and `_` a user types stay wildcards — a search for `100%` would also
     * return `1000`. Larastan refuses to type dynamic SQL on purpose, so the two
     * suppressions below are the visible marker for it. They are safe only while
     * both of these hold:
     *
     * - `$column` is never client input. It comes from {@see FieldPermissions},
     *   and is then put through the grammar's own `wrap()`.
     * - the search term is never interpolated here; it travels as a binding.
     *
     * @param  string  $column  Already wrapped by the grammar.
     * @return Expression<literal-string>
     */
    private static function likeExpression(string $column): Expression
    {
        /** @phpstan-ignore return.type, argument.type */
        return new Expression($column." LIKE ? ESCAPE '".self::LIKE_ESCAPE."'");
    }

    /**
     * Split a field into its relation path and its column.
     *
     * @return array{0: string|null, 1: string}
     */
    private static function split(string $field): array
    {
        $position = strrpos($field, '.');

        if ($position === false) {
            return [null, $field];
        }

        return [substr($field, 0, $position), substr($field, $position + 1)];
    }
}
