<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

/**
 * The `aura.errors` section, read once and typed.
 *
 * Everything here is a ceiling or a switch, and every one of them exists
 * because the client applies none of its own. Aura's reporter batches up to a
 * hundred entries, each of which may carry the whole rejected response section
 * in `metadata.receivedValue`, and it truncates nothing on the way out — so the
 * only bound on what arrives is the one this class reads.
 *
 * A missing or unreadable value falls back to the constant beside it rather
 * than to "no limit": a ceiling a broken config can switch off is not a
 * ceiling. `enabled` is the exception and falls back to `false` — an ingest
 * endpoint that appears because a config key was misspelled is worse than one
 * that does not appear at all.
 *
 * @internal
 */
final readonly class ErrorIngestConfig
{
    /** Path the ingest route is registered at. */
    public const string PATH = 'aura/errors';

    /** Middleware the route runs behind. Never `web` — see {@see self::$middleware}. */
    public const array MIDDLEWARE = ['throttle:60,1'];

    /** Bytes of request body accepted; over this the answer is 413. */
    public const int MAX_PAYLOAD = 1048576;

    /** Entries kept from one batch. The client's own queue caps at 100. */
    public const int MAX_ENTRIES = 100;

    /** Bytes of encoded `metadata` kept per entry. */
    public const int METADATA_MAX_BYTES = 8192;

    /** Table the `database` driver writes to. */
    public const string TABLE = 'aura_errors';

    /**
     * @param  bool  $enabled  Whether the route is registered at all.
     * @param  string  $path  Route path, relative to the application root.
     * @param  list<string>  $middleware  Route middleware. Aura sends no CSRF token,
     *                                    so the `web` group would answer 419 forever.
     * @param  string  $driver  `log` or `database`.
     * @param  string|null  $channel  Log channel for the `log` driver; `null` is the default one.
     * @param  string  $table  Table the `database` driver writes to.
     * @param  int  $maxPayload  Request body ceiling in bytes.
     * @param  int  $maxEntries  Entries kept from one batch; the rest are dropped, not rejected.
     * @param  bool  $storeMetadata  Whether `metadata` is stored at all.
     * @param  int  $metadataMaxBytes  Bytes of encoded `metadata` kept per entry.
     * @param  bool  $queue  Whether storing is pushed onto the queue.
     */
    public function __construct(
        public bool $enabled,
        public string $path,
        public array $middleware,
        public string $driver,
        public ?string $channel,
        public string $table,
        public int $maxPayload,
        public int $maxEntries,
        public bool $storeMetadata,
        public int $metadataMaxBytes,
        public bool $queue,
    ) {}

    /**
     * The configured section, with every value defaulted and typed.
     */
    public static function fromConfig(): self
    {
        return new self(
            enabled: config('aura.errors.enabled') === true,
            path: self::string('path', self::PATH),
            middleware: self::middleware(),
            driver: self::string('driver', 'log'),
            channel: self::nullableString('channel'),
            table: self::string('table', self::TABLE),
            maxPayload: self::positive('max_payload', self::MAX_PAYLOAD),
            maxEntries: self::positive('max_entries', self::MAX_ENTRIES),
            storeMetadata: config('aura.errors.metadata.store') !== false,
            metadataMaxBytes: self::positive('metadata.max_bytes', self::METADATA_MAX_BYTES),
            queue: config('aura.errors.queue') === true,
        );
    }

    /**
     * Is the `database` driver the configured one?
     */
    public function usesDatabase(): bool
    {
        return $this->driver === 'database';
    }

    /**
     * One `aura.errors.*` string, or the packaged default when it is empty or
     * not a string.
     */
    private static function string(string $key, string $default): string
    {
        $value = config('aura.errors.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * One optional `aura.errors.*` string. Unlike {@see self::string()} an empty
     * value means "unset", not "use the default".
     */
    private static function nullableString(string $key): ?string
    {
        $value = config('aura.errors.'.$key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * One `aura.errors.*` positive integer, or the packaged default.
     *
     * @return positive-int
     */
    private static function positive(string $key, int $default): int
    {
        $value = config('aura.errors.'.$key);

        /** @var positive-int */
        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * The route's middleware list, with anything unreadable dropped.
     *
     * An empty configured list is honoured — a host application behind its own
     * gateway may well want no middleware — but a malformed one falls back to
     * the packaged default rather than to nothing.
     *
     * @return list<string>
     */
    private static function middleware(): array
    {
        $value = config('aura.errors.middleware');

        if (! is_array($value)) {
            return self::MIDDLEWARE;
        }

        return array_values(array_filter($value, is_string(...)));
    }
}
