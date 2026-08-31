<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Query\FieldPermissions;

/**
 * The field whitelist, read back out of the cells the browser will receive.
 *
 * This is the invariant the package exists for, so it gets a name of its own:
 * what the header advertises and what the query layer will accept are not two
 * lists kept in agreement, they are one list read twice. Every entry here comes
 * from the same cell array that is about to be serialised, resolved with Aura's
 * own `reference || field || key` order.
 *
 * @internal
 */
final class ColumnPermissions
{
    /**
     * @param  list<ResolvedColumn>  $columns
     *
     * @throws InvalidDefinition When a column offers an operation it cannot name a field for.
     */
    public static function from(array $columns): FieldPermissions
    {
        // The global search is resolved first so that a multi-field column
        // which is both searched globally and sortable reports the more
        // specific of the two mistakes, as it always has.
        $globalSearch = self::globalSearchFields($columns);

        $sortable = [];
        $searchable = [];
        $filterable = [];

        foreach ($columns as $resolved) {
            if ($resolved->flag('sortable')) {
                $sortable[] = $resolved->operableField('sortable');
            }

            if ($resolved->flag('searchable')) {
                $searchable[] = $resolved->operableField('searchable');
            }

            if ($resolved->flag('filterable')) {
                $filterable[] = $resolved->operableField('filterable');
            }
        }

        return new FieldPermissions(
            sortable: $sortable,
            searchable: $searchable,
            filterable: $filterable,
            globalSearch: $globalSearch,
        );
    }

    /**
     * The fields the global search covers.
     *
     * The header publishes this same list as `settings.searchableItems`, and it
     * does so by reading it back off the whitelist rather than recomputing it —
     * `header.settings.searchableItems` and what the query layer accepts are
     * the same array or the toolbar offers a search the server refuses.
     *
     * @param  list<ResolvedColumn>  $columns
     * @return list<string>
     *
     * @throws InvalidDefinition When a multi-field column joins the global search.
     */
    private static function globalSearchFields(array $columns): array
    {
        $fields = [];

        foreach ($columns as $resolved) {
            if (! $resolved->column->wantsGlobalSearch()) {
                continue;
            }

            $field = $resolved->field();

            if ($field === null) {
                throw InvalidDefinition::multiFieldInGlobalSearch($resolved->key() ?? '');
            }

            $fields[] = $field;
        }

        return $fields;
    }
}
