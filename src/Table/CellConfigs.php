<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use TamasLabs\Aura\Cell\CellConfig;
use TamasLabs\Aura\Cell\CellRules;
use TamasLabs\Aura\Cell\Reference;
use TamasLabs\Aura\Exceptions\InvalidDefinition;

/**
 * The `body.columnConfigs` map, and the fields its conditions compare
 * numerically.
 *
 * Keyed by **field**, not by column key. The schema says otherwise, but
 * `TableBodyRow.tsx` looks a single-field column's renderer up under
 * `columnConfigs[column.field]` and a multi-field column's under each member
 * field's own name; `columnConfigs[column.key]` is read for one thing only,
 * `cellRules`. Keying by the documented key would produce a payload that
 * validates and renders nothing.
 *
 * Four things can put an entry in the map, and they are applied in this order
 * per column — a column's own renderer, its per-field renderers, its escalated
 * actions, then its cell rules — because the rules attach to whatever the
 * others left behind.
 *
 * @internal
 */
final class CellConfigs
{
    /** @var array<string, array<string, mixed>> */
    private array $configs = [];

    /** @var list<string> */
    private array $numeric = [];

    private function __construct() {}

    /**
     * @param  list<ResolvedColumn>  $columns
     * @param  string|null  $resource  The table's route base, for escalated actions.
     *
     * @throws InvalidDefinition
     */
    public static function from(array $columns, ?string $resource = null): self
    {
        $cells = new self;

        foreach ($columns as $resolved) {
            // Per column, in this order: the entries a column contributes are
            // decided together, and the rules read what the renderers wrote.
            $cells->addRenderer($resolved);
            $cells->addFieldRenderers($resolved);
            $cells->addActions($resolved, $resource);
            $cells->addRules($resolved);
        }

        return $cells;
    }

    /**
     * The finished map, ready for `body.columnConfigs`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function configs(): array
    {
        return $this->configs;
    }

    /**
     * The item fields these configurations compare numerically.
     *
     * @return list<string>
     */
    public function numericFields(): array
    {
        return array_values(array_unique($this->numeric));
    }

    /**
     * The column's own renderer, attached under the field it reads.
     *
     * @throws InvalidDefinition
     */
    private function addRenderer(ResolvedColumn $resolved): void
    {
        $config = $resolved->column->cellConfig();

        if (! $config instanceof CellConfig) {
            return;
        }

        $key = $resolved->key();
        $field = $resolved->field();

        if ($resolved->fields() !== null) {
            throw InvalidDefinition::configOnMultiFieldColumn($key ?? '');
        }

        if ($field === null || $field !== $key) {
            throw InvalidDefinition::configNeedsMatchingKey($key ?? '', $field);
        }

        $this->claim($field, $key);

        $this->configs[$field] = $config->resolve($field, $resolved->cell);
        $this->numeric = [...$this->numeric, ...$config->numericFields($field)];
    }

    /**
     * The per-field renderers of a multi-field column, one entry each.
     *
     * @throws InvalidDefinition
     */
    private function addFieldRenderers(ResolvedColumn $resolved): void
    {
        $key = $resolved->key();
        $field = $resolved->field();
        $fields = $resolved->fields();

        foreach ($resolved->column->fieldConfigs() as $member => $config) {
            $allowed = $fields ?? ($field === null ? [] : [$field]);

            if (! in_array($member, $allowed, true)) {
                throw InvalidDefinition::configureUnknownField($key ?? '', $member, $allowed);
            }

            $this->claim($member, $key ?? '');

            $this->configs[$member] = $config->resolve($member, $resolved->cell);
            $this->numeric = [...$this->numeric, ...$config->numericFields($member)];
        }
    }

    /**
     * The configurations of any customised action, one entry per action field.
     *
     * An action that was left alone contributes nothing: Aura generates its
     * configuration in the browser, from the resource base only the browser
     * knows. An action that was customised cannot be left to that — the
     * generated configuration would not carry the customisation — so it emits
     * the whole entry here, and the preprocessor skips the field on finding one.
     *
     * @throws InvalidDefinition
     */
    private function addActions(ResolvedColumn $resolved, ?string $resource): void
    {
        $key = $resolved->key() ?? '';

        foreach ($resolved->actions() as $action) {
            if (! $action->isEscalated()) {
                continue;
            }

            $field = $action->field();

            $this->claim($field, $key);

            $this->configs[$field] = $action->resolve($key, $resource, $resolved->cell);
        }
    }

    /**
     * The column's conditional `<td>` styling, attached under the column key —
     * the one thing Aura does read from `columnConfigs[column.key]`.
     *
     * @throws InvalidDefinition
     */
    private function addRules(ResolvedColumn $resolved): void
    {
        $rules = $resolved->column->cellRules();
        $key = $resolved->key();

        if (! $rules instanceof CellRules || $key === null) {
            return;
        }

        $field = $resolved->field();
        $fields = $resolved->fields();

        // Same rule as a renderer, for the same reason — and here also because
        // an invented key could collide with another column's field and
        // overwrite that column's renderer.
        if ($fields === null && $field !== $key) {
            throw InvalidDefinition::configNeedsMatchingKey($key, $field);
        }

        // A multi-field column has no field for the conditions to fall back on,
        // so `$read` below would be the column key — a name the rows do not
        // carry. Aura reads `undefined`, every condition is false, and the cell
        // is never styled, with nothing said anywhere. Unconditional rules are
        // fine: they emit no `key` at all.
        if ($fields !== null && $rules->isConditional() && $rules->conditionField() === null) {
            throw InvalidDefinition::rulesNeedField($key);
        }

        $read = $field ?? $key;

        $this->configs[$key] = self::withRules(
            $this->configs[$key] ?? null,
            $rules,
            $read,
            $fields,
            $resolved->cell,
        );

        $this->numeric = [...$this->numeric, ...$rules->numericFields($read)];
    }

    /**
     * Two columns cannot render the same field differently.
     *
     * `columnConfigs` is one flat map keyed by field, so a second configuration
     * for a field already spoken for does not sit beside the first — it
     * replaces it, and the column that lost renders whatever the winner says.
     * Aura has no way to tell them apart, so the definition refuses to build
     * rather than pick.
     *
     * @throws InvalidDefinition
     */
    private function claim(string $field, string $key): void
    {
        if (array_key_exists($field, $this->configs)) {
            throw InvalidDefinition::conflictingCellConfig($field, $key);
        }
    }

    /**
     * Attach cell rules to the entry Aura reads them from.
     *
     * `cellRules` is looked up at `columnConfigs[column.key]`, which for a
     * column with no renderer of its own does not exist yet — and cannot be an
     * entry carrying only rules, because every entry needs a `type`. The
     * stand-in is a `reference` config, which is what the cell was already
     * doing: reading the column's field and running it through the formatter
     * chain, inherited here from the heading so the rendering does not change.
     *
     * @param  array<string, mixed>|null  $existing
     * @param  list<string>|null  $fields
     * @param  array<string, mixed>  $cell
     * @return array<string, mixed>
     */
    private static function withRules(?array $existing, CellRules $rules, string $read, ?array $fields, array $cell): array
    {
        if ($existing !== null) {
            $existing['cellRules'] = $rules->resolve($read);

            return $existing;
        }

        $stand = Reference::make();

        if ($fields !== null) {
            $stand->set('fields', $fields);
        }

        return $stand->rules($rules)->resolve($read, $cell);
    }
}
