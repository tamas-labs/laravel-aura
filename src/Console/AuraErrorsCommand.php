<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;
use TamasLabs\Aura\Errors\ErrorIngestConfig;

/**
 * `php artisan aura:errors`
 *
 * The reason the ingest is worth having in *this* package rather than in the
 * host application. Aura names the offending field in `key` — `header`,
 * `body.columnConfigs`, a column's own name — and this package is what
 * generated the document that field came from. So the question the command
 * answers is not "were there errors" but "which of my table definitions is
 * breaking the contract, and how often".
 *
 * Grouped by `key` for that reason, and not by `message`: the message is
 * rendered through Aura's `labels` and changes with the host's language, while
 * the key does not.
 *
 * It reads rows, so it needs the `database` driver. Under the default `log`
 * driver there is nothing to read and the command says so rather than printing
 * an empty table, which would look like good news.
 */
#[AsCommand(name: 'aura:errors')]
final class AuraErrorsCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'aura:errors
        {--limit=20 : How many groups to show}
        {--key= : Only this error key}
        {--severity= : Only this severity}';

    /**
     * @var string
     */
    protected $description = 'List the errors Aura reported, grouped by key';

    /**
     * Print the groups, newest first.
     *
     * @internal
     */
    public function handle(): int
    {
        $config = ErrorIngestConfig::fromConfig();

        if (! $config->usesDatabase()) {
            $this->components->warn(sprintf(
                'aura.errors.driver is "%s", so there is nothing to read. Switch it to "database" '
                .'(and publish the migration) to keep queryable rows.',
                $config->driver,
            ));

            return self::FAILURE;
        }

        $rows = $this->groups($config);

        if ($rows === []) {
            $this->components->info('No errors reported.');

            return self::SUCCESS;
        }

        $this->table(
            ['Key', 'Severity', 'Type', 'Component', 'Distinct', 'Occurrences', 'Received', 'Last seen'],
            $rows,
        );

        return self::SUCCESS;
    }

    /**
     * One row per `key`, ordered by when it was last seen.
     *
     * `occurrences` and `receipts` are summed separately because they count
     * different things: the first is how often the error happened in browsers,
     * the second how often the entry reached this server — retries included.
     *
     * @return list<list<string>>
     */
    private function groups(ErrorIngestConfig $config): array
    {
        $query = DB::table($config->table)
            ->selectRaw('error_key, severity, type, component, count(*) as distinct_errors, '
                .'sum(occurrences) as occurrences, sum(receipts) as receipts, '
                .'max(last_received_at) as last_seen')
            ->groupBy('error_key', 'severity', 'type', 'component')
            ->orderByDesc('last_seen')
            ->limit($this->intOption('limit', 20));

        $key = $this->stringOption('key');
        $severity = $this->stringOption('severity');

        if ($key !== null) {
            $query->where('error_key', $key);
        }

        if ($severity !== null) {
            $query->where('severity', $severity);
        }

        return array_values($query->get()->map(fn (object $row): array => [
            self::cell($row->error_key ?? '—'),
            self::cell($row->severity),
            self::cell($row->type),
            self::cell($row->component),
            self::cell($row->distinct_errors),
            self::cell($row->occurrences),
            self::cell($row->receipts),
            self::cell($row->last_seen),
        ])->all());
    }

    /**
     * One aggregate value as a table cell.
     *
     * Everything read back off a query builder row is `mixed`, and the sums in
     * particular come back as a string on some drivers and an integer on
     * others.
     */
    private static function cell(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * One integer option, or the default when it is not a positive number.
     */
    private function intOption(string $name, int $default): int
    {
        $value = $this->option($name);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : $default;
    }

    /**
     * One optional string option.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
