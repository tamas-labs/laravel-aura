<?php

declare(strict_types=1);

/**
 * Import guard.
 *
 * An import is a claim about what a file depends on, and the layer map of this
 * package is read off those claims — by a person, and by any architecture tool
 * pointed at `src/`. An import that exists only so a `{@see}` link can be
 * spelled shortly makes a claim the code does not: three of them used to point
 * *up* the layers (`Support\JsonMap` at `Table\TableBlueprint`, `Cell\CellConfig`
 * and `Cell\ConditionalBuilder` at `Table\Column`), and a cycle check would have
 * reported cycles that do not exist at runtime.
 *
 * The rule is therefore: a `use` in shipped code has to be named by code or by
 * a type annotation. A docblock reference spells the class out in full instead
 * — longer to read, but it says what it is, and it is invisible to everything
 * that resolves imports.
 *
 * `pint.json` is the other half: the `laravel` preset's
 * `fully_qualified_strict_types` imports docblock symbols, so the formatter
 * would put every one of them back and this guard would fail on freshly
 * formatted code. `import_symbols` is off there, and it is off for this reason.
 */

/**
 * Tags whose body is prose rather than a type.
 *
 * `@see` and `@link` are references, `@deprecated` and `@example` are sentences.
 * Everything else a docblock declares here — `@param`, `@return`, `@var`,
 * `@template`, `@extends`, `@phpstan-*` — is a type position, and a name used
 * there is a real dependency of the file.
 */
const AURA_PROSE_TAGS = ['see', 'link', 'deprecated', 'example'];

/**
 * The imports a source names nowhere but in its prose.
 *
 * The split is done on tokens rather than with a regex, so a `//` inside a
 * string literal cannot swallow the rest of a line of code and turn a real use
 * into a phantom one.
 *
 * @return list<string>
 */
function auraProseOnlyImports(string $source): array
{
    preg_match_all('/^use ([\w\\\\]+)(?: as (\w+))?;$/m', $source, $matches, PREG_SET_ORDER);

    if ($matches === []) {
        return [];
    }

    $stripped = preg_replace('/^use [\w\\\\]+(?: as \w+)?;$/m', '', $source) ?? $source;
    [$code, $types] = auraSplitSource($stripped);

    $prose = [];

    foreach ($matches as $match) {
        $short = $match[2] ?? '';

        if ($short === '') {
            $parts = explode('\\', $match[1]);
            $short = end($parts);
        }

        $pattern = '/\b'.preg_quote($short, '/').'\b/';

        if (preg_match($pattern, $code) === 1 || preg_match($pattern, $types) === 1) {
            continue;
        }

        $prose[] = $match[1];
    }

    return $prose;
}

/**
 * One source split into the part that runs and the part that declares types.
 *
 * Anything left over — a docblock's sentences, a `{@see}` link, a fenced
 * example — is neither, and is what the guard refuses to accept as a reason for
 * an import.
 *
 * @return array{0: string, 1: string}
 */
function auraSplitSource(string $source): array
{
    $code = '';
    $types = '';

    foreach (token_get_all($source) as $token) {
        if (is_string($token)) {
            $code .= $token."\n";

            continue;
        }

        if ($token[0] !== T_DOC_COMMENT && $token[0] !== T_COMMENT) {
            $code .= $token[1]."\n";

            continue;
        }

        $tag = null;

        foreach (explode("\n", $token[1]) as $line) {
            // A tag holds until the next one: `@param array{` may wrap, and the
            // type is then on a line carrying no tag of its own.
            if (preg_match('/^[\s*\/]*@([\w-]+)/', $line, $found) === 1) {
                $tag = strtolower($found[1]);
            }

            if ($tag !== null && ! in_array($tag, AURA_PROSE_TAGS, true)) {
                $types .= $line."\n";
            }
        }
    }

    return [$code, $types];
}

it('imports nothing it names only in a docblock', function (): void {
    $offenders = [];

    foreach (auraShippedFiles() as $path => $source) {
        foreach (auraProseOnlyImports($source) as $import) {
            $offenders[] = $path.': '.$import;
        }
    }

    // Spell the class out in the docblock instead:
    // `{@see \TamasLabs\Aura\Table\Column::make()}`.
    expect($offenders)->toBe([]);
});

it('reads the files it claims to guard', function (): void {
    // Without this the test above passes on an empty scan, which is the one
    // failure a guard cannot report on itself.
    $files = auraShippedFiles();

    expect(count($files))->toBeGreaterThan(50)
        ->and($files)->toHaveKey('routes/aura-errors.php')
        ->and($files)->toHaveKey('src/Table/Column.php');
});

it('sees the difference between a type, a link and a mention', function (): void {
    // Guard the guard: four imports, one of which is only ever linked to.
    $source = <<<'PHP'
        <?php

        use Aura\Linked;
        use Aura\Named;
        use Aura\Typed;
        use Aura\Used;

        /**
         * A sentence about Named, and a {@see Linked} beside it.
         *
         * @param  Typed  $value
         */
        function demo($value)
        {
            return new Used;
        }
        PHP;

    // `Named` is a bare mention in prose and `Linked` a reference: neither is a
    // dependency. A code example in a docblock counts the same way — it is not
    // compiled, and `CellConfig` carried a `Column` import for exactly one.
    expect(auraProseOnlyImports($source))->toBe(['Aura\Linked', 'Aura\Named']);
});
