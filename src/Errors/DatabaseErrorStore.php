<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

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
    public function __construct(private ErrorIngestConfig $config) {}

    /**
     * Write the records, and answer how many were new.
     *
     * The number is new rows, not rows touched: a batch delivered twice reports
     * its records the first time and none the second, which is what makes the
     * deduplication visible in the response body.
     *
     * @param  list<AuraErrorRecord>  $records
     */
    public function store(array $records): int
    {
        $inserted = 0;

        foreach ($records as $record) {
            $inserted += $this->write($record) ? 1 : 0;
        }

        return $inserted;
    }

    /**
     * Write one record. Answers whether it created a row.
     */
    private function write(AuraErrorRecord $record): bool
    {
        if ($this->touch($record)) {
            return false;
        }

        try {
            DB::table($this->config->table)->insert($this->row($record));

            return true;
        } catch (QueryException) {
            // Another request inserted the same fingerprint between the update
            // above and this insert. The unique index is what makes that a lost
            // race rather than a duplicate row — fold into the existing one.
            $this->touch($record);

            return false;
        }
    }

    /**
     * Fold a repeat into the row that is already there. Answers whether there
     * was one.
     */
    private function touch(AuraErrorRecord $record): bool
    {
        $table = $this->config->table;

        $existing = DB::table($table)
            ->where('fingerprint', $record->fingerprint)
            ->first(['id', 'occurrences', 'receipts']);

        if ($existing === null) {
            return false;
        }

        DB::table($table)->where('id', $existing->id)->update([
            'occurrences' => max($record->count, self::number($existing->occurrences)),
            'receipts' => self::number($existing->receipts) + 1,
            'last_occurred_at' => $record->lastOccurredAt() ?? $record->occurredAt(),
            'last_received_at' => $record->context->receivedAt,
        ]);

        return true;
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
            'last_occurred_at' => $record->lastOccurredAt(),
            'received_at' => $record->context->receivedAt,
            'last_received_at' => $record->context->receivedAt,
            'ip' => $record->context->ip,
            'user_agent' => $record->context->userAgent,
            'referer' => $record->context->referer,
            'user_id' => $record->context->userId === null ? null : (string) $record->context->userId,
        ];
    }
}
