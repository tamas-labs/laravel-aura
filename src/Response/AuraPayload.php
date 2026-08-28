<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Response;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Contracts\Support\Arrayable;
use TamasLabs\Aura\Exceptions\UnsupportedPaginator;

/**
 * The data half of an Aura response: `items`, `meta` and `links`.
 *
 * The describing half — `header`, `body`, `footer` — comes from the table
 * definition and is merged in by the caller until F3 lands. On its own this is
 * not yet a valid response: the contract requires `header`.
 */
final readonly class AuraPayload
{
    /**
     * @param  list<mixed>  $items
     * @param  array<string, mixed>  $meta
     * @param  array<string, string|null>  $links
     */
    private function __construct(
        public array $items,
        public array $meta,
        public array $links,
    ) {}

    /**
     * Build the payload from a paginator.
     *
     * @template TValue
     *
     * @param  Paginator<int, TValue>|CursorPaginator<int, TValue>  $paginator
     *
     * @throws UnsupportedPaginator When the paginator cannot report `last_page` / `total`.
     */
    public static function fromPaginator(Paginator|CursorPaginator $paginator): self
    {
        if (! $paginator instanceof LengthAwarePaginator) {
            throw UnsupportedPaginator::for($paginator);
        }

        return new self(
            items: self::items($paginator),
            meta: [
                'current_page' => $paginator->currentPage(),
                'from' => $paginator->firstItem(),
                'last_page' => $paginator->lastPage(),
                'path' => (string) $paginator->path(),
                'per_page' => $paginator->perPage(),
                'to' => $paginator->lastItem(),
                'total' => $paginator->total(),
            ],
            links: [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        );
    }

    /**
     * The three keys, ready to be merged with the describing half of the response.
     *
     * @return array{items: list<mixed>, meta: array<string, mixed>, links: array<string, string|null>}
     */
    public function toArray(): array
    {
        return [
            'items' => $this->items,
            'meta' => $this->meta,
            'links' => $this->links,
        ];
    }

    /**
     * The rows, flattened to plain data.
     *
     * `array_values` on purpose: the contract types `items` as an array, and a
     * paginator page with gaps in its keys would serialise to a JSON object.
     *
     * @template TValue
     *
     * @param  LengthAwarePaginator<int, TValue>  $paginator
     * @return list<mixed>
     */
    private static function items(LengthAwarePaginator $paginator): array
    {
        return array_values(array_map(
            static fn (mixed $item): mixed => $item instanceof Arrayable ? $item->toArray() : $item,
            $paginator->items(),
        ));
    }
}
