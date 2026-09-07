<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Writes records as rows, one per distinct error.
 *
 * This is the driver that makes the feature answer a question rather than just
 * record an event: rows can be grouped by `error_key` to find the table
 * definition that is breaking the contract. The price is a table, which is why
 * the migration is published rather than loaded and the driver is not the
 * default.
 *
 * **Idempotence is the point.** Aura re-sends a batch after any non-2xx answer
 * — four attempts, then again behind an exponential backoff — so an endpoint
 * that stored the batch and then failed to answer will be handed the same
 * entries again. Every write is therefore keyed on the record's fingerprint: a
 * repeat updates the row it already has.
 *
 * What a repeat updates is deliberately narrow. `receipts` counts arrivals,
 * which is the server's number; `occurrences` takes the higher of the two
 * counts, which is the client's, because Aura merges repeats into one entry and
 * the count only grows. Conflating them would turn a retried batch into a spike
 * of user-visible errors.
 */
final readonly class DatabaseErrorStore implements ErrorStore
{
    /**
     * Rows per statement.
     *
     * `max_entries` is the host's to raise, and every driver caps the
     * placeholders one statement may carry — PostgreSQL at 65 535, SQLite lower
     * on an older build. Nineteen columns times this stays clear of all of them,
     * and the packaged cap of 100 entries still comes to one statement.
     */
    private const int CHUNK = 100;

    /**
     * What a repeat may move.
     *
     * `occurred_at` and `received_at` are absent on purpose: they are when the
     * error was *first* seen. Everything here is worked out in PHP from the row
     * read back, so the statement carries values rather than a driver-specific
     * `GREATEST` or `receipts + 1` expression.
     */
    private const array UPDATED = ['occurrences', 'receipts', 'last_occurred_at', 'last_received_at'];

    public function __construct(private ErrorIngestConfig $config) {}

    /**
     * Write the records, and answer how many were new.
     *
     * The number is new rows, not rows touched: a batch delivered twice reports
     * its records the first time and none the second, which is what makes the
     * deduplication visible in the response body.
     *
     * **Two queries, whatever the batch size.** One `whereIn` reads the rows the
     * batch already has, and one `upsert` writes every record — the counters
     * arrive already worked out, so nothing here needs a driver-specific
     * `GREATEST` or `receipts + 1` expression, and the unique index on
     * `fingerprint` is what decides insert from update. A record-at-a-time loop
     * cost two queries *per entry*: 200 on a default-capped batch, synchronously,
     * inside the request, and with `throttle:60,1` in front of it that is 12 000
     * queries a minute from one IP.
     *
     * **This is where the interface's "must not throw" is honoured.** The
     * driver needs a table the host has to publish and migrate itself, so the
     * likely first run of the feature is the one where the write raises — and
     * the record fields are all bounded before they get here, so what fails is
     * the storage, not the record. Whatever fails is reported exactly once, and
     * the answer is the count that actually landed: never the whole batch, and
     * — see {@see self::salvage()} — not none of it either.
     *
     * @param  list<AuraErrorRecord>  $records
     */
    public function store(array $records): int
    {
        $merged = self::mergeByFingerprint($records);

        if ($merged === []) {
            return 0;
        }

        try {
            $existing = $this->existing(array_keys($merged));
        } catch (Throwable $e) {
            report($e);

            return 0;
        }

        $rows = [];
        $fresh = [];

        foreach ($merged as $fingerprint => $entry) {
            $prior = $existing[$fingerprint] ?? null;

            $row = $this->row($entry['record']);
            $row['occurrences'] = max($entry['count'], $prior['occurrences'] ?? 0);
            $row['receipts'] = ($prior['receipts'] ?? 0) + $entry['arrivals'];

            $rows[] = $row;
            $fresh[] = $prior === null;
        }

        return $this->flush($rows, $fresh);
    }

    /**
     * Send the rows, chunk by chunk, and answer how many were new.
     *
     * A chunk is one statement, so it is all or nothing — and one poison record
     * would otherwise cost the ninety-nine beside it. A failed chunk is
     * therefore reported once and then walked a row at a time, which is what the
     * record-at-a-time version did for the whole batch: `stored` stays the
     * number that actually landed, neither the whole batch nor none of it.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<bool>  $fresh
     */
    private function flush(array $rows, array $fresh): int
    {
        $inserted = 0;
        $freshChunks = array_chunk($fresh, self::CHUNK);

        foreach (array_chunk($rows, self::CHUNK) as $index => $chunk) {
            $chunkFresh = $freshChunks[$index] ?? [];

            try {
                $this->upsert($chunk);
            } catch (Throwable $e) {
                report($e);

                // Reported once, as the record-at-a-time version reported once.
                // The salvage pass is recovery from a fault already named, not a
                // second fault, and it stops where that loop stopped.
                return $inserted + $this->salvage($chunk, $chunkFresh);
            }

            $inserted += count(array_filter($chunkFresh));
        }

        return $inserted;
    }

    /**
     * Write a failed chunk one row at a time, stopping at the first row that
     * will not go in.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<bool>  $fresh
     */
    private function salvage(array $rows, array $fresh): int
    {
        $inserted = 0;

        foreach ($rows as $index => $row) {
            try {
                $this->upsert([$row]);
            } catch (Throwable) {
                break;
            }

            $inserted += ($fresh[$index] ?? false) ? 1 : 0;
        }

        return $inserted;
    }

    /**
     * The one statement this driver writes with. The unique index on
     * `fingerprint` is what decides insert from update.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        DB::table($this->config->table)->upsert($rows, ['fingerprint'], self::UPDATED);
    }

    /**
     * One entry per fingerprint, because a statement must not carry one key
     * twice — and the drivers disagree about what happens when it does.
     *
     * PostgreSQL refuses the whole statement ("ON CONFLICT DO UPDATE command
     * cannot affect row a second time"). SQLite and MySQL accept it and let the
     * last row win, which is the worse outcome of the two: measured on SQLite,
     * two arrivals of one fingerprint left `receipts` at 1 and took the last
     * `count` rather than the highest — wrong counters, no error. Folding here
     * makes every driver do what the record-at-a-time loop did: the last entry
     * describes the row, the count is the highest any of them claimed — the
     * client's number, never a sum — and each entry is one arrival.
     *
     * @param  list<AuraErrorRecord>  $records
     * @return array<string, array{record: AuraErrorRecord, count: int, arrivals: int}>
     */
    private static function mergeByFingerprint(array $records): array
    {
        $merged = [];

        foreach ($records as $record) {
            $seen = $merged[$record->fingerprint] ?? null;

            $merged[$record->fingerprint] = [
                'record' => $record,
                'count' => $seen === null ? $record->count : max($seen['count'], $record->count),
                'arrivals' => $seen === null ? 1 : $seen['arrivals'] + 1,
            ];
        }

        return $merged;
    }

    /**
     * The counters the batch's fingerprints already have, keyed by fingerprint.
     *
     * Read before the write rather than folded into it: `occurrences` takes the
     * higher of the two counts and `receipts` adds to what is there, and both
     * are worked out here so the statement stays portable. The read and the
     * write are not one atomic step, which the record-at-a-time version was not
     * either — its update was the same read-modify-write. Two requests carrying
     * one fingerprint at the same moment can therefore leave `receipts` one
     * short, and both may report the record as new. The row itself cannot
     * double: the unique index is what prevents that, and it is unchanged.
     *
     * @param  list<string>  $fingerprints
     * @return array<string, array{occurrences: int, receipts: int}>
     */
    private function existing(array $fingerprints): array
    {
        $rows = [];

        foreach (array_chunk($fingerprints, self::CHUNK) as $chunk) {
            $found = DB::table($this->config->table)
                ->whereIn('fingerprint', $chunk)
                ->get(['fingerprint', 'occurrences', 'receipts']);

            foreach ($found as $row) {
                $fingerprint = $row->fingerprint ?? null;

                if (is_string($fingerprint)) {
                    $rows[$fingerprint] = [
                        'occurrences' => self::number($row->occurrences ?? null),
                        'receipts' => self::number($row->receipts ?? null),
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * A counter read back off a row. A column the migration declares
     * `unsignedInteger` still arrives as a string on some drivers, and as
     * `mixed` to a static analyser either way.
     */
    private static function number(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * The record as a row.
     *
     * @return array<string, mixed>
     */
    private function row(AuraErrorRecord $record): array
    {
        return [
            'fingerprint' => $record->fingerprint,
            'severity' => $record->severity,
            'type' => $record->type,
            'component' => $record->component,
            'action' => $record->action,
            'error_key' => $record->key,
            'message' => $record->message,
            'details' => $record->details,
            'metadata' => $record->metadata === null ? null : json_encode($record->metadata),
            'occurrences' => $record->count,
            'receipts' => 1,
            'occurred_at' => $record->occurredAt(),
            'last_occurred_at' => $record->lastOccurredAt() ?? $record->occurredAt(),
            'received_at' => $record->context->receivedAt,
            'last_received_at' => $record->context->receivedAt,
            'ip' => $record->context->ip,
            'user_agent' => $record->context->userAgent,
            'referer' => $record->context->referer,
            'user_id' => $record->context->userId === null ? null : (string) $record->context->userId,
        ];
    }
}
