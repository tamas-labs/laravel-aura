<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Request;

/**
 * One entry of the request's `sortable[]`.
 *
 * The field is what the header cell declared as its `reference`, falling back to
 * its `field` — Aura resolves that before sending, so what arrives here is
 * already the name to order by.
 */
final readonly class Sort
{
    /**
     * @param  'asc'|'desc'  $direction  Validated on the way in, so the query layer
     *                                   never has to re-check it.
     */
    public function __construct(
        public string $field,
        public string $direction,
    ) {}
}
