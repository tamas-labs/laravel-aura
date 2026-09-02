<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The optional queued half of an ingest: writing an accepted batch.
 *
 * Dispatched only when `aura.errors.queue` is on. Storing is synchronous by
 * default because the work is one write and a queue that is not running would
 * turn a report into a silently pending job — the failure mode this whole
 * feature exists to remove.
 *
 * The job carries the records, not the request: everything the server had to
 * read off the HTTP layer is already in {@see ErrorContext} by the time this is
 * dispatched.
 *
 * @internal
 */
final class StoreErrorReport implements ShouldQueue
{
    /**
     * @param  list<AuraErrorRecord>  $records
     */
    public function __construct(private readonly array $records) {}

    /**
     * Hand the records to the configured store.
     */
    public function handle(ErrorStore $store): void
    {
        $store->store($this->records);
    }
}
