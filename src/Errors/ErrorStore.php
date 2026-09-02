<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

/**
 * Where an accepted batch is written.
 *
 * Two implementations ship: {@see LogErrorStore}, which needs no
 * infrastructure, and {@see DatabaseErrorStore}, which needs the published
 * migration but can be filtered, counted and deduplicated. Bind your own
 * against this interface to send the batch somewhere else.
 *
 * An implementation must not throw for a bad record. The endpoint answers `202`
 * whatever happens to the batch, because Aura re-sends anything else forever;
 * an exception here would turn a storage hiccup into an unkillable retry loop.
 */
interface ErrorStore
{
    /**
     * Write these records and answer how many landed.
     *
     * The number is what the response body reports, and it may legitimately be
     * lower than `count($records)`: a store that deduplicates counts a repeat
     * as an update, not as a new record.
     *
     * @param  list<AuraErrorRecord>  $records
     */
    public function store(array $records): int;
}
