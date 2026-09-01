<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Exceptions;

use RuntimeException;

/**
 * Raised when a paginator cannot satisfy the Aura response contract.
 */
final class UnsupportedPaginator extends RuntimeException implements AuraException
{
    /**
     * The contract requires `meta.last_page` and `meta.total`; neither
     * `simplePaginate()` nor `cursorPaginate()` knows them, because neither runs
     * the count query. Only `LengthAwarePaginator` can answer.
     *
     * @internal
     */
    public static function for(object $paginator): self
    {
        return new self(sprintf(
            'Aura needs a LengthAwarePaginator, got %s. The response contract requires meta.last_page '
            .'and meta.total, which simplePaginate() and cursorPaginate() cannot supply — use paginate().',
            $paginator::class,
        ));
    }
}
