<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Query;

/**
 * Which fields a client is allowed to sort, search and filter by.
 *
 * Every `field` in an Aura request comes from the browser, so it is attacker
 * controlled. Handing one straight to `orderBy()` leaks the existence and the
 * ordering of columns the table never meant to expose. This object is the
 * whitelist, and it is deliberately closed: there is no "allow everything"
 * switch, and an empty list means nothing is allowed rather than everything.
 *
 * Field names are matched exactly — a prefix of an allowed name is not allowed.
 * Dotted names (`company.name`) are permitted and resolve through the relation.
 *
 * From F3 onwards the table definition builds this; until then callers pass the
 * lists themselves.
 */
final readonly class FieldPermissions
{
    /**
     * @param  list<string>  $sortable  Fields `sortable[].field` may name.
     * @param  list<string>  $searchable  Fields `searchable[].field` may name.
     * @param  list<string>  $filterable  Fields `filterable[].field` may name.
     * @param  list<string>  $globalSearch  Fields the toolbar's global search covers.
     */
    public function __construct(
        public array $sortable = [],
        public array $searchable = [],
        public array $filterable = [],
        public array $globalSearch = [],
    ) {}

    /**
     * Nothing is allowed — the safe starting point.
     */
    public static function none(): self
    {
        return new self;
    }

    /**
     * May the client sort by this field?
     */
    public function allowsSort(string $field): bool
    {
        return in_array($field, $this->sortable, true);
    }

    /**
     * May the client search this field?
     */
    public function allowsSearch(string $field): bool
    {
        return in_array($field, $this->searchable, true);
    }

    /**
     * May the client filter by this field?
     */
    public function allowsFilter(string $field): bool
    {
        return in_array($field, $this->filterable, true);
    }
}
