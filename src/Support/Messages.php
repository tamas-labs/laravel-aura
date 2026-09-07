<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Support;

use Illuminate\Support\Facades\Lang;

/**
 * The package's own user-facing messages, resolved through the translator.
 *
 * One seam, for the same reason the validator layer on the Vue side has one:
 * the namespace and the fallback are decided here rather than at nine call
 * sites, and a reader looking for "what does this package say to a user" has a
 * single file to open.
 *
 * **`trans()` and `__()` are not available here.** They are declared in
 * `Illuminate\Foundation\helpers.php`, and `illuminate/foundation` is not among
 * the granular components this package requires — the same trap as
 * `app()->hasDebugModeEnabled()` in `Response\MissingFields` and `FormRequest`
 * in the error ingest. The `Lang` facade lives in `illuminate/support` and
 * reaches the same translator.
 *
 * Only what an end user can see goes through here. A message addressed to
 * whoever wrote the table — `InvalidDefinition` and everything else implementing
 * `AuraException` — stays in English on purpose: it names a mistake in the
 * definition, it is read in a stack trace or a log, and translating it would
 * only make it harder to search for.
 *
 * @internal
 */
final class Messages
{
    /**
     * The translation namespace `AuraServiceProvider` registers, so a line is
     * addressed as `aura::validation.not_sortable`.
     */
    public const NAMESPACE = 'aura';

    /**
     * One line, with its placeholders filled in.
     *
     * `Lang::get()` rather than `Lang::string()`: the second throws when the key
     * resolves to an array, and a published lang file with a stray `return []`
     * would turn a 422 into a 500 — the one outcome this package spends the most
     * effort avoiding. A line that is not a string degrades to the key, which is
     * also what the translator answers for a key it cannot find at all.
     *
     * @param  string  $key  Group and line, without the namespace: `validation.not_sortable`.
     * @param  array<string, string|int|float>  $replace
     */
    public static function get(string $key, array $replace = []): string
    {
        $namespaced = self::NAMESPACE.'::'.$key;

        $line = Lang::get($namespaced, $replace);

        return is_string($line) ? $line : $namespaced;
    }
}
