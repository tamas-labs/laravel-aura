<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Table\Column;

/**
 * How one column's data cells are rendered — an entry of `body.columnConfigs`.
 *
 * A configuration is a separate object from the {@see Column}
 * on purpose. The column describes the *heading* and what the server will let
 * the client do with the field; the configuration describes the *cell*. They
 * carry overlapping key names (`align`, `currency`, `class`) with different
 * destinations, and merging the two builders would make which one you were
 * setting a matter of guesswork.
 *
 * Two things about attaching one are worth knowing, because Aura documents
 * neither and both fail quietly:
 *
 * - **Once a configuration is attached, the heading's formatting no longer
 *   reaches the cell.** The renderer is handed the config alone
 *   (`TableBodyRow.tsx` → `renderSegmentNode`), so a `currency()` column with a
 *   plain configuration would render raw figures. The column's formatter
 *   settings are therefore copied in as defaults, and an explicit call on the
 *   configuration still wins.
 * - **`columnConfigs` is keyed by the column's *field*, not its key**, whatever
 *   the schema says: `TableBodyRow.tsx` looks up `columnConfigs[column.field]`
 *   for a single-field column and one entry per member field for a multi-field
 *   one. Only `cellRules` is read from `columnConfigs[column.key]`. The table
 *   refuses to attach a configuration where the two would disagree.
 */
abstract class CellConfig extends ConditionalBuilder
{
    /**
     * Formatter and alignment settings a column can hand down to its cells.
     *
     * `datetime`, `time` and `raw` are absent from the config schemas but read
     * by the renderer all the same (`buildFormatConfig.ts`), and the configs
     * allow additional properties — so passing them on is both valid and the
     * only way a datetime column keeps its time after gaining a configuration.
     */
    private const INHERITED = [
        'number', 'currency', 'date', 'datetime', 'time', 'phone', 'unit', 'raw',
        'slice', 'uppercase', 'lowercase', 'capitalize', 'monospace',
        'padStart', 'padEnd', 'chars', 'align',
    ];

    protected ?CellRules $cellRules = null;

    /**
     * The `type` discriminator Aura dispatches on.
     */
    abstract public function type(): string;

    /**
     * What the contract requires this type to carry.
     *
     * Every entry is one requirement, and every requirement is a list of
     * alternatives, each of which is the set of keys that alternative needs.
     * All the requirements have to hold, any one alternative satisfies each.
     *
     * The schema waives them as soon as the config is conditional, because a
     * branch can supply what the base leaves out.
     *
     * @return list<list<list<string>>>
     */
    abstract protected function requires(): array;

    /**
     * Does this type read a value out of the row? Those default their `field`
     * to the column's.
     */
    protected function readsField(): bool
    {
        return false;
    }

    /**
     * Does this type run the value through the formatter chain? Those inherit
     * the column's formatting.
     */
    protected function formats(): bool
    {
        return false;
    }

    /**
     * Keys whose presence makes a defaulted `field` meaningless — or harmful.
     *
     * A config reading `fields` has no use for a single one, and a stacked
     * progress bar that also carried a `field` would draw one plain bar instead
     * of its segments. An explicit `field` is never affected; this only decides
     * whether the column's is filled in.
     *
     * @return list<string>
     */
    protected function supersedesField(): array
    {
        return ['fields'];
    }

    /**
     * Does this type need `key` emitted even when nothing is conditional?
     *
     * Only where `key` doubles as the mapping selector — Aura looks a mapping
     * up under `field ?? key`, so a type with no `field` of its own has nothing
     * else to select on.
     *
     * @param  array<string, mixed>  $settings
     */
    protected function needsKey(array $settings): bool
    {
        return false;
    }

    /**
     * The `key` to emit when {@see self::needsKey()} holds.
     *
     * Defaults to the field the configuration is attached to, which is what a
     * mapping selects on. A type whose `key` means something else — the row
     * field a route is built from, say — overrides this.
     *
     * @param  array<string, mixed>  $settings
     * @param  array<string, mixed>  $headerCell
     */
    protected function keyFor(string $field, array $settings, array $headerCell): string
    {
        return $field;
    }

    /**
     * Last chance to fill in settings that need the field or the heading.
     *
     * Called on the copy {@see self::resolve()} works on, never on the builder
     * the caller holds: resolving a configuration must not change it. A type
     * that has to compute something from `$field` overrides this rather than
     * writing to `$this` before delegating upwards.
     *
     * @param  array<string, mixed>  $headerCell
     */
    protected function prepare(string $field, array $headerCell): void {}

    /**
     * Prepare this configuration and every branch below it.
     *
     * A branch never goes through {@see self::resolve()} — it is emitted from
     * `settings()` alone — so anything a type computes in {@see self::prepare()}
     * would be silently missing from it. That is not hypothetical: a `Modal`
     * given its trigger inside a `when()` would emit the condition and nothing
     * else, and the branch would change nothing on the page.
     *
     * Each branch is prepared on a copy, for the same reason the base is.
     *
     * @param  array<string, mixed>  $headerCell
     */
    private function prepareTree(string $field, array $headerCell): void
    {
        $this->prepare($field, $headerCell);

        foreach ($this->branches as $index => $entry) {
            $branch = clone $entry['branch'];
            $branch->prepareTree($field, $headerCell);

            $this->branches[$index]['branch'] = $branch;
        }

        if ($this->else instanceof self) {
            $else = clone $this->else;
            $else->prepareTree($field, $headerCell);

            $this->else = $else;
        }
    }

    /**
     * Give every leaf branch the `key` its renderer needs.
     *
     * A conditional configuration cannot carry that `key` at the root: there it
     * is the condition selector, and `stripLogicProps` removes it before the
     * renderer sees the config. `stripBranchProps` keeps the branch's own, so
     * that is where it has to go — which matters exactly once, and silently:
     * `renderIconNode` wraps the glyph in an `<a>` only when `route` **and**
     * `key` are both present, so a per-row condition over a linking icon would
     * hide the cell correctly and then render the surviving rows without their
     * link.
     *
     * The decision is made per branch, against the settings that branch is
     * actually resolved with (Aura merges the branch over the base), because
     * the route may sit in the branch and not in the base.
     *
     * @param  array<string, mixed>  $headerCell
     * @param  array<string, mixed>  $base  The settings a branch is merged over.
     */
    private function keyBranches(string $field, array $headerCell, array $base): void
    {
        foreach ($this->branches as $entry) {
            $entry['branch']->keyAsBranch($field, $headerCell, $base);
        }

        if ($this->else instanceof self) {
            $this->else->keyAsBranch($field, $headerCell, $base);
        }
    }

    /**
     * This configuration seen as one branch of the one above it.
     *
     * A branch that has conditions of its own is not a leaf: its `key` is the
     * selector for the level below and gets stripped in turn, so the search
     * carries on downwards instead of stopping here.
     *
     * @param  array<string, mixed>  $headerCell
     * @param  array<string, mixed>  $base
     */
    private function keyAsBranch(string $field, array $headerCell, array $base): void
    {
        $settings = array_merge($base, $this->settings());

        if ($this->isConditional()) {
            $this->keyBranches($field, $headerCell, $settings);

            return;
        }

        if ($this->conditionKey !== null || ! $this->needsKey($settings)) {
            return;
        }

        $this->conditionKey = $this->keyFor($field, $settings, $headerCell);
    }

    /**
     * Conditional styling of the `<td>` this content sits in.
     */
    public function rules(CellRules $rules): static
    {
        $this->cellRules = $rules;

        return $this;
    }

    /**
     * The finished configuration.
     *
     * @param  string  $field  The item field this configuration is attached to.
     * @param  array<string, mixed>  $headerCell  The column's header cell, for the settings it hands down.
     * @return array<string, mixed>
     *
     * @throws InvalidDefinition When the configuration cannot render anything.
     */
    public function resolve(string $field, array $headerCell = []): array
    {
        $config = clone $this;

        $config->prepareTree($field, $headerCell);

        if ($config->readsField() && ! $config->fieldIsSuperseded()) {
            $config->default('field', $field);
        }

        if ($config->formats()) {
            $config->inheritFormatting($headerCell);
        }

        $settings = $config->settings();

        $config->assertRenderable($settings);

        if ($config->isConditional()) {
            $config->keyBranches($field, $headerCell, $settings);
        }

        $resolved = ['type' => $config->type()] + $settings + $config->conditionals($field);

        if (! array_key_exists('key', $resolved) && $config->needsKey($settings)) {
            $resolved['key'] = $config->keyFor($field, $settings, $headerCell);
        }

        if ($config->cellRules instanceof CellRules) {
            $resolved['cellRules'] = $config->cellRules->resolve($field);
        }

        return $resolved;
    }

    /**
     * Item fields this configuration and its cell rules compare numerically.
     *
     * @return list<string>
     */
    public function numericFields(string $defaultKey): array
    {
        $fields = parent::numericFields($defaultKey);

        if ($this->cellRules instanceof CellRules) {
            foreach ($this->cellRules->numericFields($defaultKey) as $field) {
                $fields[] = $field;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Has the caller already said where the value comes from?
     */
    private function fieldIsSuperseded(): bool
    {
        foreach ($this->supersedesField() as $key) {
            if (array_key_exists($key, $this->attributes)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Copy the column's formatter settings in as defaults.
     *
     * @param  array<string, mixed>  $headerCell
     */
    private function inheritFormatting(array $headerCell): void
    {
        foreach (self::INHERITED as $key) {
            if (array_key_exists($key, $headerCell)) {
                $this->default($key, $headerCell[$key]);
            }
        }
    }

    /**
     * Would this configuration render anything at all?
     *
     * @param  array<string, mixed>  $settings
     *
     * @throws InvalidDefinition
     */
    private function assertRenderable(array $settings): void
    {
        // A conditional config may be empty at the base: the matching branch
        // supplies the content. This mirrors the schema, which waives the same
        // requirements in the presence of `if` or `else`.
        if ($this->isConditional()) {
            return;
        }

        foreach ($this->requires() as $requirement) {
            if (! self::satisfied($requirement, $settings)) {
                throw InvalidDefinition::incompleteCellConfig($this->type(), $requirement);
            }
        }
    }

    /**
     * @param  list<list<string>>  $alternatives
     * @param  array<string, mixed>  $settings
     */
    private static function satisfied(array $alternatives, array $settings): bool
    {
        foreach ($alternatives as $keys) {
            $present = true;

            foreach ($keys as $key) {
                $present = $present && array_key_exists($key, $settings);
            }

            if ($present) {
                return true;
            }
        }

        return false;
    }
}
