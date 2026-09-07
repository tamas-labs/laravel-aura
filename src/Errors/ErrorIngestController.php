<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

/**
 * The ingest endpoint: one POST, one answer.
 *
 * There are exactly three answers, and the set is short for a reason. Aura's
 * reporter treats every non-2xx as a failure, retries the batch four times and
 * then re-queues it behind an exponential backoff, so a status code is not a
 * message to a developer — it is an instruction to the client, and every 4xx
 * that a retry cannot resolve becomes an unkillable loop.
 *
 * - **`202 Accepted`** — the normal answer, *including* when entries were
 *   dropped. The body reports how many were stored and why the rest were not;
 *   the client ignores it, a developer with curl does not.
 * - **`413`** — the body was over `aura.errors.max_payload`. Spent knowingly:
 *   the client cannot send a smaller batch, so it will retry, but the
 *   alternative is accepting an unbounded body from the browser.
 * - **`429`** — from the `throttle` middleware, which the client's own backoff
 *   handles correctly.
 *
 * @internal
 */
final class ErrorIngestController
{
    /**
     * Accept one batch of reported errors.
     */
    public function __invoke(Request $request, ErrorIngestConfig $config): JsonResponse
    {
        $size = strlen($request->getContent());

        if ($size > $config->maxPayload) {
            return new JsonResponse([
                'message' => sprintf(
                    'Payload of %d bytes is over the %d byte limit.',
                    $size,
                    $config->maxPayload,
                ),
            ], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        $batch = ErrorBatch::fromRequest($request, $config);
        $stored = $this->dispatch($batch, $config);

        return new JsonResponse([
            'received' => $batch->received,
            'stored' => $stored,
            'dropped' => $batch->dropped,
            'reasons' => $batch->reasons,
        ], Response::HTTP_ACCEPTED);
    }

    /**
     * Store the batch, on the queue when it is configured.
     *
     * A queued run cannot report how many records landed, so it answers with
     * the number handed over. That is the honest number at the time of the
     * response — the alternative would be waiting for the job to prove it.
     *
     * **Nothing thrown below this line reaches the client.** The interface says
     * an implementation must not throw, but the interface is the one part of
     * this feature a host application replaces, and the two shipped stores
     * still depend on infrastructure that can be missing — the `database`
     * driver with an unpublished migration is the likely first run, and it is
     * exactly the case where a 500 would be worst: Aura retries a failed batch
     * four times, puts it back at the *front* of its queue and repeats it
     * behind an exponential backoff, so broken telemetry would multiply its own
     * traffic forever. The guarantee therefore lives here, where it can be
     * enforced, rather than in the documentation of the interface. Resolving
     * the store is inside the `try` for the same reason: a misconfigured
     * binding fails the same way a write does.
     *
     * **This is why the store is not method-injected**, unlike the config
     * beside it. Method injection resolves before the action runs — outside
     * this `try`, in the router — so a host whose binding throws would get the
     * 500 the whole feature is built to avoid. The config can be injected
     * because it is a singleton the provider's `boot()` has already resolved,
     * so nothing is left to fail at parameter-resolution time.
     *
     * The exception is reported through the application's handler, so it lands
     * wherever the host already looks for errors. `0` stored is the honest
     * number — the client is told it was accepted, not that it was written,
     * which is all a `202` ever promised.
     */
    private function dispatch(ErrorBatch $batch, ErrorIngestConfig $config): int
    {
        if ($batch->records === []) {
            return 0;
        }

        try {
            if (! $config->queue) {
                return app(ErrorStore::class)->store($batch->records);
            }

            app(Dispatcher::class)->dispatch(new StoreErrorReport($batch->records));

            return count($batch->records);
        } catch (Throwable $e) {
            report($e);

            return 0;
        }
    }
}
