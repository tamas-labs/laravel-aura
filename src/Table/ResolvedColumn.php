<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use TamasLabs\Aura\Exceptions\InvalidDefinition;

/**
 * One column together with the header cell it resolved to.
 *
 * The two travel as a pair through the whole build, and everything downstream
 * needs both halves: the cell is what the browser receives and what the
 * whitelist is read back out of, while the column still holds what never
 * reaches the wire as a header key — the renderer, the cell rules, the
 * data-cell classes, and whether the column asked to join the global search.
 *
 * This was a `array{0: Column, 1: array<string, mixed>}` tuple destructured in
 * eight places. Naming it costs one class and buys the accessors below, which
 * is where the "is this a multi-field column?" and "what field will Aura send?"
 * questions now have a single answer each.
 *
 * @internal
 */
final readonly class ResolvedColumn
{
    /**
     * @param  array<string, mixed>  $cell  The finished header cell.
     */
    public function __construct(
        public Column $column,
        public array $cell,
    ) {}

    /**
     * The column's key, if the cell carries a usable one.
     */
    public function key(): ?string
    {
        $key = $this->cell['key'] ?? null;

        return is_string($key) ? $key : null;
    }

    /**
     * The single field this column reads, if it reads one.
     */
    public function field(): ?string
    {
        $field = $this->cell['field'] ?? null;

        return is_string($field) ? $field : null;
    }

    /**
     * The member fields of a multi-field column — `null` when this column is
     * not one, which is the question most callers are actually asking.
     *
     * @return list<string>|null
     */
    public function fields(): ?array
    {
        $fields = $this->cell['fields'] ?? null;

        if (! is_array($fields)) {
            return null;
        }

        return array_values(array_filter($fields, is_string(...)));
    }

    /**
     * Every field this cell names, whether it names one or several.
     *
     * @return list<string>
     */
    public function declaredFields(): array
    {
        $fields = $this->fields();

        if ($fields !== null) {
            return $fields;
        }

        $field = $this->field();

        return $field === null ? [] : [$field];
    }

    /**
     * Was this column built by {@see Column::actions()}?
     */
    public function isActionColumn(): bool
    {
        return $this->column->isActionColumn();
    }

    /**
     * Is this header-cell flag set?
     */
    public function flag(string $key): bool
    {
        return (bool) ($this->cell[$key] ?? false);
    }

    /**
     * The field Aura will actually send for this cell.
     *
     * Aura resolves it as `reference || field || key`, so the whitelist has to
     * agree exactly — an entry naming the wrong one of the three turns every
     * click on that column into a 422.
     *
     * @throws InvalidDefinition When a multi-field column names no reference.
     */
    public function operableField(string $operation): string
    {
        foreach (['reference', 'field'] as $candidate) {
            $value = $this->cell[$candidate] ?? null;

            if (is_string($value)) {
                return $value;
            }
        }

        // Aura would fall back to the key, which for a multi-field column is a
        // name the database has never heard of.
        throw InvalidDefinition::multiFieldNeedsReference($this->key() ?? '', $operation);
    }
}
