<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Request;

/**
 * One entry of the request's `searchable[]`.
 *
 * Two shapes share the slot: a term search carries `term` (with `exact`), a
 * range search carries `min` / `max` instead, either of which may be null for an
 * open end.
 */
final readonly class Search
{
    public function __construct(
        public string $field,
        public ?string $term = null,
        public bool $exact = false,
        public string|int|float|null $min = null,
        public string|int|float|null $max = null,
    ) {}

    /**
     * Is this the range shape rather than the term shape?
     *
     * @internal
     */
    public function isRange(): bool
    {
        return $this->min !== null || $this->max !== null;
    }
}
