<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Request;

/**
 * One entry of the request's `filterable[]` — the values picked in a column's
 * filter dropdown. A row matches when the column equals any of them.
 */
final readonly class Filter
{
    /**
     * @param  list<mixed>  $values
     */
    public function __construct(
        public string $field,
        public array $values,
    ) {}
}
