<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Response;

/**
 * The fields a definition actually reads, and a row narrowed to them.
 *
 * A model row is everything the model is not hiding, which is a wider answer
 * than the table asked: a five-column table over a twenty-five-column model
 * sends twenty extra values per row, and an eager-loaded relation sends its
 * whole row too. The default stays that way — the payload's shape is public
 * surface — and {@see \TamasLabs\Aura\Table\AuraTable::$onlyDeclaredFields}
 * turns this on.
 *
 * **Read out of the emitted definition, never declared twice.** The same
 * argument as the field whitelist: what the browser is handed is the only
 * honest answer to what the browser reads. Anything named as a `field`,
 * `fields`, `reference` or `key`, plus every `{placeholder}` in a route, is
 * kept — a condition's `key` names the field it compares, an action column's
 * key names the identifier its route is built from, and a `columnConfigs` entry
 * is keyed by the field it renders.
 *
 * The collection is deliberately **wider than strictly necessary**: a column
 * key that is not a field costs an unused entry, and a field left out would
 * blank a cell. It cannot see a field named only inside a hand-written
 * `merge()` payload, which is why the switch is opt-in and
 * {@see \TamasLabs\Aura\Table\AuraTable::transform()} is the full answer.
 *
 * @internal
 */
final readonly class RowFields
{
    /**
     * Property names that hold a field, or a list of them.
     */
    private const NAMES = ['field', 'reference', 'key'];

    /**
     * A tree of dot paths: `true` keeps the value whole, an array narrows into
     * it. `company` and `company.name` together keep the whole company —
     * whatever asked for all of it wins.
     *
     * @param  array<string, true|array<string, mixed>>  $tree
     */
    private function __construct(private array $tree) {}

    /**
     * The fields the definition names.
     *
     * @param  array<string, mixed>  $definition  `header`, `body`, `footer`.
     */
    public static function fromDefinition(array $definition): self
    {
        $paths = [];

        self::collect($definition, $paths);

        $tree = [];

        foreach (array_unique($paths) as $path) {
            // An empty segment is dropped rather than narrowed into: a `merge()`
            // payload can carry any string, and `company.` has to keep the whole
            // company, not narrow it to nothing.
            $tree = self::insert($tree, array_values(array_filter(
                explode('.', $path),
                static fn (string $segment): bool => $segment !== '',
            )));
        }

        return new self($tree);
    }

    /**
     * The paths, as they were collected. For tests and for `aura:` tooling.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        return self::flatten($this->tree, '');
    }

    /**
     * A page of rows, narrowed.
     *
     * Anything that is not a row — a paginator of plain lists, a value object
     * that answered `toArray()` with one — is handed back untouched. There is
     * no field to look up in it.
     *
     * @param  list<mixed>  $items
     * @return list<mixed>
     */
    public function narrowAll(array $items): array
    {
        $rows = [];

        foreach ($items as $item) {
            if (is_array($item) && ! array_is_list($item)) {
                /** @var array<string, mixed> $item */
                $rows[] = $this->narrow($item);

                continue;
            }

            $rows[] = $item;
        }

        return $rows;
    }

    /**
     * One row, narrowed.
     *
     * A key the definition does not name is dropped; a key it names as a path
     * (`company.name`) is narrowed in turn, into a nested row or into every row
     * of a nested list. A value that is not an array is kept as it is — a
     * `null` relation is still a `null` relation.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function narrow(array $row): array
    {
        $narrowed = [];

        foreach ($row as $key => $value) {
            $node = $this->tree[$key] ?? null;

            if ($node === null) {
                continue;
            }

            $narrowed[$key] = $node === true ? $value : self::narrowValue($value, $node);
        }

        return $narrowed;
    }

    /**
     * A nested value, narrowed by the subtree that named it.
     *
     * @param  array<string, mixed>  $node
     */
    private static function narrowValue(mixed $value, array $node): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        /** @var array<string, true|array<string, mixed>> $node */
        $nested = new self($node);

        if (! array_is_list($value)) {
            /** @var array<string, mixed> $value */
            return $nested->narrow($value);
        }

        $rows = [];

        foreach ($value as $item) {
            if (is_array($item) && ! array_is_list($item)) {
                /** @var array<string, mixed> $item */
                $rows[] = $nested->narrow($item);

                continue;
            }

            $rows[] = $item;
        }

        return $rows;
    }

    /**
     * Walk the definition and note every field name it carries.
     *
     * @param  list<string>  $paths
     */
    private static function collect(mixed $node, array &$paths): void
    {
        if (! is_array($node)) {
            return;
        }

        foreach ($node as $key => $value) {
            if (is_string($key)) {
                self::collectFrom($key, $value, $paths);
            }

            self::collect($value, $paths);
        }
    }

    /**
     * One property of one node.
     *
     * `columnConfigs` is keyed by field, so its own keys are field names too —
     * the one place a name is a key rather than a value.
     *
     * @param  list<string>  $paths
     */
    private static function collectFrom(string $key, mixed $value, array &$paths): void
    {
        if (in_array($key, self::NAMES, true) && is_string($value) && $value !== '') {
            $paths[] = $value;

            return;
        }

        if ($key === 'fields' && is_array($value)) {
            foreach ($value as $field) {
                if (is_string($field) && $field !== '') {
                    $paths[] = $field;
                }
            }

            return;
        }

        if ($key === 'columnConfigs' && is_array($value)) {
            foreach (array_keys($value) as $field) {
                if (is_string($field) && $field !== '') {
                    $paths[] = $field;
                }
            }

            return;
        }

        // A route is filled per row: `users/{id}/edit` reads `id` off the row.
        if ($key === 'route' && is_string($value) && preg_match_all('/\{([\w.]+)\}/', $value, $matches) > 0) {
            foreach ($matches[1] as $placeholder) {
                $paths[] = $placeholder;
            }
        }
    }

    /**
     * Add one dot path to the tree.
     *
     * @param  array<string, true|array<string, mixed>>  $tree
     * @param  list<string>  $path
     * @return array<string, true|array<string, mixed>>
     */
    private static function insert(array $tree, array $path): array
    {
        $step = array_shift($path);

        // Only a name that was nothing but dots gets here.
        if ($step === null) {
            return $tree;
        }

        $existing = $tree[$step] ?? null;

        if ($path === [] || $existing === true) {
            $tree[$step] = $path === [] ? true : $existing;

            return $tree;
        }

        /** @var array<string, true|array<string, mixed>> $children */
        $children = is_array($existing) ? $existing : [];

        $tree[$step] = self::insert($children, $path);

        return $tree;
    }

    /**
     * The tree back as dot paths.
     *
     * @param  array<string, true|array<string, mixed>>  $tree
     * @return list<string>
     */
    private static function flatten(array $tree, string $prefix): array
    {
        $paths = [];

        foreach ($tree as $step => $node) {
            $path = $prefix === '' ? $step : $prefix.'.'.$step;

            if ($node === true) {
                $paths[] = $path;

                continue;
            }

            /** @var array<string, true|array<string, mixed>> $node */
            $paths = array_merge($paths, self::flatten($node, $path));
        }

        return $paths;
    }
}
