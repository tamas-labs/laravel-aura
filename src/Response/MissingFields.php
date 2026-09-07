<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Response;

use Illuminate\Support\Facades\Log;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;

/**
 * Warns, in debug mode, when the definition reads through something the rows
 * do not carry.
 *
 * {@see Column::make()} with `company.name`, over a {@see AuraTable::query()}
 * that forgot `->with('company')`, renders an empty column and says nothing.
 * There is not even an N+1 to notice: `toArray()` does not load a lazy
 * relation, so the key is simply absent from every row. What the developer sees
 * is a blank column, and the first place they look is the cell configuration —
 * the one place the answer cannot be.
 *
 * The check reads the **rows** rather than the query's eager loads, which is
 * what makes it quiet enough to leave switched on:
 *
 * - a key absent from the row means nothing produced it — a missing eager load,
 *   or a {@see AuraTable::transform()} that dropped it;
 * - a key present and `null` is a relation that is loaded and empty *for this
 *   row*, which is ordinary data and never warned about;
 * - a dotted path that is not a relation at all — a JSON cast read as
 *   `meta.theme` — carries its root in the row, so it stays silent too.
 *
 * Only dotted paths are examined. A flat name absent from the rows is normal
 * and often deliberate: `edit_icon` is a header field with no value behind it,
 * and an action column's key names an identifier the definition cannot verify
 * (see {@see Column::actions()}). And only the first row is read — every row of
 * one page has the same shape.
 *
 * A warning, never an exception: a definition may legitimately name a field
 * only some pages carry, and nothing here is worth failing a request over.
 *
 * @internal
 */
final class MissingFields
{
    /**
     * Report every dotted field the page cannot resolve.
     *
     * @param  array<string, mixed>  $definition  The definition the browser receives.
     * @param  list<mixed>  $items  The rows, as they are about to be sent.
     * @param  string  $table  The table class, for the message.
     */
    public static function report(array $definition, array $items, string $table): void
    {
        if (config('app.debug') !== true || $items === []) {
            return;
        }

        $row = $items[0];

        if (! is_array($row) || array_is_list($row)) {
            return;
        }

        foreach (RowFields::fromDefinition($definition)->paths() as $path) {
            $missing = self::firstMissing($path, $row);

            if ($missing === null) {
                continue;
            }

            Log::warning(sprintf(
                'Aura: %s reads "%s", but the rows carry no "%s" — that cell renders empty. '
                .'The usual cause is a missing ->with(\'%s\') in query().',
                $table,
                $path,
                $missing,
                $missing,
            ), ['table' => $table, 'field' => $path, 'missing' => $missing]);
        }
    }

    /**
     * The shortest prefix of the path this row does not carry, or `null` when
     * it carries the whole way down.
     *
     * The last segment is the attribute and is deliberately not checked: an
     * attribute the model hides, or one that is simply null, is data rather
     * than a wiring mistake. Everything before it is a step the row has to be
     * able to take.
     *
     * The row is typed no more narrowly than it arrives: it came out of a
     * paginator through {@see AuraTable::transform()}, so its keys are only
     * strings by convention.
     *
     * @param  array<mixed, mixed>  $row
     */
    private static function firstMissing(string $path, array $row): ?string
    {
        $segments = explode('.', $path);

        array_pop($segments);

        $node = $row;
        $walked = [];

        foreach ($segments as $segment) {
            if (! is_array($node) || array_is_list($node)) {
                // A to-many relation, or a scalar: there is no name to index by
                // here, and Aura would not resolve one either.
                return null;
            }

            $walked[] = $segment;

            if (! array_key_exists($segment, $node)) {
                return implode('.', $walked);
            }

            $node = $node[$segment];

            if ($node === null) {
                // Loaded, and empty for this row. Ordinary data.
                return null;
            }
        }

        return null;
    }
}
