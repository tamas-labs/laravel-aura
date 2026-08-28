<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use TamasLabs\Aura\Query\FieldPermissions;

/**
 * Everything about a table that does not depend on the request: the blocks that
 * describe it, and the fields it allows the client to operate on.
 *
 * The two travel together because they are two readings of one definition — the
 * `sortable: true` the browser is shown and the field the query layer will
 * accept are decided in the same place, by the same column. Caching one without
 * the other is how a header offers a sort the server then refuses.
 *
 * Flattened to arrays for the cache rather than serialised as objects: a cached
 * object outlives the class it was serialised from, and comes back from a
 * deploy as garbage.
 */
final readonly class TableBlueprint
{
    /**
     * @param  array<string, mixed>  $definition  `header`, and `body` / `footer` when they carry anything.
     */
    public function __construct(
        public array $definition,
        public FieldPermissions $permissions,
    ) {}

    /**
     * @return array{definition: array<string, mixed>, fields: array{sortable: list<string>, searchable: list<string>, filterable: list<string>, globalSearch: list<string>}}
     */
    public function toArray(): array
    {
        return [
            'definition' => $this->definition,
            'fields' => [
                'sortable' => $this->permissions->sortable,
                'searchable' => $this->permissions->searchable,
                'filterable' => $this->permissions->filterable,
                'globalSearch' => $this->permissions->globalSearch,
            ],
        ];
    }

    /**
     * Rebuild from what {@see self::toArray()} stored.
     *
     * @param  array<array-key, mixed>  $cached
     */
    public static function fromArray(array $cached): self
    {
        $fields = $cached['fields'] ?? [];
        $fields = is_array($fields) ? $fields : [];

        return new self(
            definition: self::stringKeyed($cached['definition'] ?? []),
            permissions: new FieldPermissions(
                sortable: self::strings($fields, 'sortable'),
                searchable: self::strings($fields, 'searchable'),
                filterable: self::strings($fields, 'filterable'),
                globalSearch: self::strings($fields, 'globalSearch'),
            ),
        );
    }

    /**
     * The stored definition, with any non-string key dropped.
     *
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $definition = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $definition[$key] = $item;
            }
        }

        return $definition;
    }

    /**
     * One field list, with anything that is not a string dropped.
     *
     * A cache entry is not input, but it is also not a value this process
     * produced — a stale or truncated one must not be able to widen a whitelist.
     *
     * @param  array<array-key, mixed>  $fields
     * @return list<string>
     */
    private static function strings(array $fields, string $key): array
    {
        $value = $fields[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
