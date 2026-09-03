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
        $globalSearch = array_map(
            static fn (array $pair): string => $pair['query'],
            self::globalSearchFields($columns),
        );

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
     * The item fields the header publishes as `settings.searchableItems`.
     *
     * Not the same list as the whitelist above, and deliberately so. Aura reads
     * this one out of the *rows* — `validateHeaderSettings` refuses an entry
     * that is not the `field` of a header cell, and the client-side global
     * search resolves it against the item — while the whitelist names what the
     * query layer puts in a `WHERE`. For a rendered column the two differ:
     * a `role_name` cell with `->reference('roles.name')` publishes the first
     * and searches the second.
     *
     * @param  list<ResolvedColumn>  $columns
     * @return list<string>
     *
     * @throws InvalidDefinition When a multi-field column joins the global search.
     */
    public static function searchableItems(array $columns): array
    {
        return array_map(
            static fn (array $pair): string => $pair['item'],
            self::globalSearchFields($columns),
        );
    }

    /**
     * The columns in the global search, each as the pair of names it goes out
     * under: `item` for the browser, `query` for the server.
     *
     * @param  list<ResolvedColumn>  $columns
     * @return list<array{item: string, query: string}>
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

            $item = $resolved->field();

            // The header half is asserted first: a multi-field column can name
            // a reference for the query and still have nothing to publish as a
            // searchable item, and that is the more specific mistake.
            if ($item === null) {
                throw InvalidDefinition::multiFieldInGlobalSearch($resolved->key() ?? '');
            }

            $fields[] = [
                'item' => $item,
                'query' => $resolved->operableField('globalSearch'),
            ];
        }

        return $fields;
    }
}
