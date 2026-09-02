<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

use Illuminate\Support\Carbon;

/**
 * One reported error, normalised for storage.
 *
 * This is what a store is handed — the entry as it arrived, minus the parts a
 * server has no use for, plus the parts only the server knows:
 *
 * - **`level` is dropped.** It is a verbatim copy of `severity` that Aura keeps
 *   for ECS compatibility and marks `@deprecated` in its own types; storing
 *   both would only invite them to disagree.
 * - **`metadata` is truncated, and can be switched off entirely.** It is
 *   unbounded on the client and is the one field that can carry what a user
 *   typed: `SessionStateValidator` reports the persisted session state, which
 *   holds their search terms and filter values.
 * - **`count` is the client's number and stays the client's number.** It counts
 *   *occurrences* in one browser, merged by Aura's own error store. The
 *   server's own repeat counter — how many times this entry was *received* —
 *   is a different number and belongs to the store, not here. Conflating the
 *   two would turn a retried batch into a spike of user-visible errors.
 *
 * The `fingerprint` is what makes a store idempotent. Aura re-sends a batch
 * after any non-2xx answer, four times and then again behind a backoff, so an
 * endpoint that succeeded but answered 500 will see the same entries again;
 * without a stable identity the error table ends up measuring its own outages.
 */
final readonly class AuraErrorRecord
{
    /** Characters kept in a name-like field (`component`, `action`, `key`). */
    public const int MAX_NAME = 255;

    /** Characters kept in a prose field (`message`, `details`). */
    public const int MAX_TEXT = 2000;

    /**
     * @param  string  $severity  `critical` | `error` | `warning` | `info` | `debug`.
     * @param  string  $type  Error category — `validation`, `api`, `client`, …
     * @param  string  $component  Emitting validator, store or component.
     * @param  string  $action  The operation that failed.
     * @param  string  $message  End-user text. Translated through Aura's `labels`,
     *                           so it is not a stable identifier — group on `key`.
     * @param  string|null  $key  Stable identifier: the offending field name, or
     *                            `component.action.type` when the caller gave none.
     * @param  string|null  $details  Raw developer-facing text.
     * @param  array<string, mixed>|null  $metadata  Truncated context, or `null` when
     *                                               storing it is switched off.
     * @param  string  $timestamp  ISO 8601 time of the **first** occurrence.
     * @param  string|null  $lastTimestamp  ISO 8601 time of the most recent one, if it repeated.
     * @param  int  $count  Occurrences the *client* merged into this entry — `count ?? 1`.
     * @param  string  $fingerprint  `sha256(key|component|action|type|message|timestamp)`.
     */
    public function __construct(
        public string $severity,
        public string $type,
        public string $component,
        public string $action,
        public string $message,
        public ?string $key,
        public ?string $details,
        public ?array $metadata,
        public string $timestamp,
        public ?string $lastTimestamp,
        public int $count,
        public string $fingerprint,
        public ErrorContext $context,
    ) {}

    /**
     * Build a record from one validated entry.
     *
     * The entry is assumed to have passed {@see ErrorBatch}: every required
     * field is present and every enum is one of the contract's values. What is
     * left to do here is bounding — the client applies no length limit to
     * anything it sends.
     *
     * @param  array<string, mixed>  $entry
     *
     * @internal
     */
    public static function fromEntry(array $entry, ErrorContext $context, ErrorIngestConfig $config): self
    {
        $severity = self::string($entry['severity'] ?? null);
        $type = self::string($entry['type'] ?? null);
        $component = (string) self::text($entry['component'] ?? null, self::MAX_NAME);
        $action = (string) self::text($entry['action'] ?? null, self::MAX_NAME);
        $message = (string) self::text($entry['message'] ?? null, self::MAX_TEXT);
        $key = self::text($entry['key'] ?? null, self::MAX_NAME);
        $timestamp = (string) self::text($entry['timestamp'] ?? null, 64);

        return new self(
            severity: $severity,
            type: $type,
            component: $component,
            action: $action,
            message: $message,
            key: $key,
            details: self::text($entry['details'] ?? null, self::MAX_TEXT),
            metadata: self::metadata($entry['metadata'] ?? null, $config),
            timestamp: $timestamp,
            lastTimestamp: self::text($entry['lastTimestamp'] ?? null, 64),
            count: self::count($entry['count'] ?? null),
            fingerprint: self::fingerprint($key, $component, $action, $type, $message, $timestamp),
            context: $context,
        );
    }

    /**
     * The identity two arrivals of the same entry share.
     *
     * `timestamp` is in it because it is the first occurrence's time and never
     * moves once Aura has merged an error — the entry that repeats keeps it and
     * grows `lastTimestamp` instead. Two genuinely separate errors a
     * millisecond apart therefore stay separate; one batch delivered twice
     * collapses.
     */
    public static function fingerprint(
        ?string $key,
        string $component,
        string $action,
        string $type,
        string $message,
        string $timestamp,
    ): string {
        return hash('sha256', implode('|', [$key ?? '', $component, $action, $type, $message, $timestamp]));
    }

    /**
     * The first occurrence as a date, or `null` when the client sent something
     * unparseable. Nothing validates `timestamp` beyond its being a string —
     * it is whatever the browser's clock produced.
     */
    public function occurredAt(): ?Carbon
    {
        return self::date($this->timestamp);
    }

    /**
     * The most recent occurrence as a date, if the error repeated.
     */
    public function lastOccurredAt(): ?Carbon
    {
        return $this->lastTimestamp === null ? null : self::date($this->lastTimestamp);
    }

    /**
     * The record as a flat array — what a log line and a database row are both
     * built from.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'severity' => $this->severity,
            'type' => $this->type,
            'component' => $this->component,
            'action' => $this->action,
            'key' => $this->key,
            'message' => $this->message,
            'details' => $this->details,
            'metadata' => $this->metadata,
            'occurrences' => $this->count,
            'timestamp' => $this->timestamp,
            'last_timestamp' => $this->lastTimestamp,
            'received_at' => $this->context->receivedAt,
            'ip' => $this->context->ip,
            'user_agent' => $this->context->userAgent,
            'referer' => $this->context->referer,
            'user_id' => $this->context->userId,
        ];
    }

    /**
     * A string field, bounded, with anything non-scalar read as absent.
     *
     * @internal
     */
    public static function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : mb_substr($string, 0, $limit);
    }

    /**
     * A required string field, already known to be present and valid.
     */
    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /**
     * The client's occurrence count. Absent means one — Aura only writes the
     * field once the error has repeated.
     */
    private static function count(mixed $value): int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 1;
    }

    /**
     * `metadata`, switched off or truncated to the configured budget.
     *
     * Truncation is measured on the encoded form, because that is what the size
     * actually is: `HeaderValidator` puts the whole rejected `header` section in
     * `receivedValue`. Over budget the value is replaced by a marker rather than
     * the entry dropped — an oversized `receivedValue` is precisely the case
     * worth knowing about.
     *
     * @return array<string, mixed>|null
     */
    private static function metadata(mixed $value, ErrorIngestConfig $config): ?array
    {
        if (! $config->storeMetadata || ! is_array($value) || $value === []) {
            return null;
        }

        /** @var array<string, mixed> $metadata */
        $metadata = $value;
        $encoded = json_encode($metadata);

        if ($encoded === false) {
            return ['_truncated' => 'metadata could not be encoded'];
        }

        if (strlen($encoded) <= $config->metadataMaxBytes) {
            return $metadata;
        }

        return [
            '_truncated' => sprintf(
                'metadata was %d bytes, over the %d byte budget',
                strlen($encoded),
                $config->metadataMaxBytes,
            ),
            '_keys' => array_slice(array_map(strval(...), array_keys($metadata)), 0, 20),
        ];
    }

    /**
     * Parse an ISO 8601 string the client sent, or `null` when it is not one.
     */
    private static function date(string $value): ?Carbon
    {
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
