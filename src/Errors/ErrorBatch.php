<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * One arrived batch, filtered down to the entries worth storing.
 *
 * **Nothing here ever rejects the request.** That is the whole design, and it
 * follows from what the client does with an answer it does not like: Aura takes
 * every non-2xx response for a failure, retries the same batch four times, puts
 * it back at the *front* of its queue and repeats it behind an exponential
 * backoff. A 422 over one malformed entry is therefore not a message to a
 * developer — it is an unrepeatable request repeated forever. A bad entry is
 * dropped, the rest are kept, and the count of what fell out is reported in the
 * response body and in the server's own log, where it can be acted on.
 *
 * The one thing this class is not is a schema validator. It checks what a store
 * needs in order to write a row — the required fields, the two enums, the
 * shapes — and lets everything else through, because the entry is declared
 * `additionalProperties: true` in the contract: a future Aura release may add
 * fields, and a receiver that rejects the batch over one of them would reject
 * it forever.
 *
 * This is deliberately **not** a `FormRequest`. That class lives in
 * `Illuminate\Foundation`, which is not one of the granular `illuminate/*`
 * components this package requires — and a FormRequest's failure mode is the
 * 422 the paragraph above rules out.
 *
 * @internal
 */
final readonly class ErrorBatch
{
    /** Severities the contract allows. */
    public const array SEVERITIES = ['critical', 'error', 'warning', 'info', 'debug'];

    /** Error types the contract allows. */
    public const array TYPES = [
        'validation', 'network', 'authentication', 'authorization',
        'not_found', 'server', 'client', 'api', 'unknown',
    ];

    /** Rejection reasons kept for the response body; the rest are counted only. */
    private const int MAX_REASONS = 10;

    /**
     * @param  list<AuraErrorRecord>  $records  Entries that will be stored.
     * @param  int  $received  Entries the batch contained.
     * @param  int  $dropped  Entries that fell out — malformed, or over `max_entries`.
     * @param  list<string>  $reasons  Why the first few fell out, for a developer with curl.
     */
    public function __construct(
        public array $records,
        public int $received,
        public int $dropped,
        public array $reasons,
    ) {}

    /**
     * Parse an incoming request into the records worth storing.
     */
    public static function fromRequest(Request $request, ErrorIngestConfig $config): self
    {
        $entries = self::entries($request);

        if ($entries === null) {
            return new self([], 0, 0, ['the request body carries no "errors" array']);
        }

        return self::fromEntries($entries, ErrorContext::fromRequest($request), $config);
    }

    /**
     * The same, from an already-decoded list — the seam the tests write against.
     *
     * @param  list<mixed>  $entries
     */
    public static function fromEntries(array $entries, ErrorContext $context, ErrorIngestConfig $config): self
    {
        $received = count($entries);
        $records = [];
        $reasons = [];
        $dropped = 0;

        foreach ($entries as $index => $entry) {
            if (count($records) >= $config->maxEntries) {
                // Over the ceiling the rest are dropped rather than rejected:
                // the client cannot send a shorter batch, so a 4xx here would
                // only bring the same one back.
                $dropped++;

                continue;
            }

            $reason = self::reject($entry);

            if ($reason !== null) {
                $dropped++;

                if (count($reasons) < self::MAX_REASONS) {
                    $reasons[] = sprintf('entry %d: %s', $index, $reason);
                }

                continue;
            }

            /** @var array<string, mixed> $entry */
            $records[] = AuraErrorRecord::fromEntry($entry, $context, $config);
        }

        return new self($records, $received, $dropped, $reasons);
    }

    /**
     * Why this entry cannot be stored, or `null` when it can.
     */
    private static function reject(mixed $entry): ?string
    {
        if (! is_array($entry)) {
            return 'not an object';
        }

        $validator = Validator::make($entry, [
            'severity' => ['required', Rule::in(self::SEVERITIES)],
            'type' => ['required', Rule::in(self::TYPES)],
            'timestamp' => ['required', 'string'],
            'component' => ['required', 'string'],
            'action' => ['required', 'string'],
            'message' => ['required', 'string'],
            'key' => ['sometimes', 'nullable', 'string'],
            'details' => ['sometimes', 'nullable', 'string'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'count' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'lastTimestamp' => ['sometimes', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            /** @var list<string> $messages */
            $messages = $validator->errors()->all();

            return implode(' ', array_slice($messages, 0, 3));
        }

        return null;
    }

    /**
     * The `errors` array out of the request body, or `null` when there is none.
     *
     * `level` is not read anywhere: it is a copy of `severity` the contract
     * requires and this package drops.
     *
     * @return list<mixed>|null
     */
    private static function entries(Request $request): ?array
    {
        $payload = $request->isJson() ? $request->json()->all() : $request->all();

        if (! isset($payload['errors']) || ! is_array($payload['errors'])) {
            return null;
        }

        return array_values($payload['errors']);
    }
}
