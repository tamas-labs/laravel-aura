<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Errors;

use Illuminate\Support\Facades\Log;

/**
 * Writes each record as one log line, on the configured channel.
 *
 * This is the default driver, and the reason the whole feature can be turned on
 * with a single config key: it needs no table, no migration and no queue. What
 * it cannot do is deduplicate — a batch that arrives four times writes four
 * lines. That is the trade the `database` driver exists to undo.
 *
 * The log level is the entry's own `severity`, which is already a PSR-3 level
 * name, so an Aura `warning` is a `warning` in the application's log and the
 * host's existing log routing applies to it unchanged.
 */
final readonly class LogErrorStore implements ErrorStore
{
    public function __construct(private ErrorIngestConfig $config) {}

    /**
     * Write one line per record.
     *
     * @param  list<AuraErrorRecord>  $records
     */
    public function store(array $records): int
    {
        $channel = Log::channel($this->config->channel);

        foreach ($records as $record) {
            $channel->log($record->severity, $this->line($record), $record->toArray());
        }

        return count($records);
    }

    /**
     * The message a human reads in the log.
     *
     * It leads with the `key`, because that is the field Aura keeps stable —
     * `message` is translated through the table's `labels` and changes with the
     * host application's language.
     */
    private function line(AuraErrorRecord $record): string
    {
        return sprintf(
            'Aura %s [%s] %s: %s',
            $record->type,
            $record->key ?? $record->component.'.'.$record->action,
            $record->component,
            $record->message,
        );
    }
}
