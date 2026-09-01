<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Response\RowPermissions;
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
     * The per-row gate, given the page and returning the test for one row.
     *
     * @var Closure(Collection<int, Model>): mixed|null
     */
    private ?Closure $permission = null;

    /**
     * The `type` discriminator Aura dispatches on.
     *
     * @internal
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
     * `value` is here because every renderer that reads a row value reads
     * `field` **first** and falls back to `value` — `renderBadgeNode`,
     * `renderProgressNode` and `action-node-helpers.ts` all do. So a fixed
     * label beside a defaulted field is not a fallback at all: the field wins,
     * and the label the caller wrote never appears. {@see Reference} is the one
     * exception and says so.
     *
     * @return list<string>
     */
    protected function supersedesField(): array
    {
        return ['fields', 'value'];
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
     * Render this cell only for the rows the callback allows.
     *
     * The callback is handed the row's **model**, and its answer travels to the
     * browser as a hidden flag the emitted configuration is conditioned on. A
     * denied row renders an empty cell — Aura's own mechanism: no branch
     * matched and there is no `else`.
     *
     * ```php
     * Column::make('email')->as(
     *     Link::make()->route('users/{id}')->allowedWhen(
     *         fn (User $user) => Gate::allows('view', $user),
     *     ),
     * );
     * ```
     *
     * Give it the policy the route is protected by, not a second rule that
     * happens to agree today. **Hiding a cell is not authorisation**: the row,
     * the identifier and the route are all still in the payload, and the check
     * that matters is the one on the route.
     *
     * The gate wraps whatever the configuration already is, `when()` branches
     * and all, so the two compose in the one direction that is safe — nothing
     * inside can render for a row the gate denied.
     *
     * @param  callable  $allowed  Given the row's model; anything truthy allows it.
     */
    public function allowedWhen(callable $allowed): static
    {
        return $this->allowedWhenAll(static fn (): callable => $allowed);
    }

    /**
     * The same, for a decision that has to be prepared for the whole page.
     *
     * The callback receives the page as a collection of models and returns the
     * per-row test, so a lookup the rows cannot answer on their own costs one
     * query for the page rather than one per row:
     *
     * ```php
     * Action::destroy()->allowedWhenAll(function (Collection $rows) {
     *     $locked = Lock::whereIn('post_id', $rows->modelKeys())->pluck('post_id')->flip();
     *
     *     return fn (Post $post) => ! $locked->has($post->getKey());
     * });
     * ```
     *
     * @param  callable(Collection<int, Model>): mixed  $resolver
     */
    public function allowedWhenAll(callable $resolver): static
    {
        $this->permission = $resolver(...);

        return $this;
    }

    /**
     * The gate this configuration carries, for the response to resolve.
     *
     * @return Closure(Collection<int, Model>): mixed|null
     *
     * @internal
     */
    public function rowPermission(): ?Closure
    {
        return $this->permission;
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
     *
     * @internal
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

        return $config->permission === null ? $resolved : $config->gated($resolved, $field);
    }

    /**
     * Item fields this configuration and its cell rules compare numerically.
     *
     * @return list<string>
     *
     * @internal
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
     * The finished configuration, behind its permission gate.
     *
     * The gate is a conditional configuration wrapped *around* the one that was
     * resolved: the root carries the flag as its `key` and one `if` branch
     * holding everything else, and deliberately no `else`. Aura merges the
     * branch over the root when the flag is exactly `true`, recursing into it
     * when the configuration was conditional in its own right, and returns
     * `null` — an empty cell — when it is not.
     *
     * Wrapping rather than adding a branch to the configuration itself is what
     * makes the two compose. A configuration has one condition field, so a gate
     * sharing the level with the caller's own `when()` would have to evaluate
     * both against the same field, and an `otherwise()` beneath it would render
     * the cell for exactly the rows the gate denied. Outside, the gate cannot
     * be reached past.
     *
     * Everything travels into the branch except `cellRules`, which is not part
     * of the content: Aura reads it from `columnConfigs[column.key]` on its own
     * and styles the `<td>` whether or not anything renders inside it.
     *
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     *
     * @throws InvalidDefinition When the gate would push the nesting past Aura's cap.
     */
    private function gated(array $resolved, string $field): array
    {
        $depth = $this->depth() + 1;

        if ($depth > self::MAX_DEPTH) {
            throw InvalidDefinition::conditionsTooDeep($depth, self::MAX_DEPTH);
        }

        $rules = $resolved['cellRules'] ?? null;

        unset($resolved['type'], $resolved['cellRules']);

        $gate = [
            'type' => $this->type(),
            'key' => RowPermissions::fieldFor($field),
            'if' => [['true' => true] + $resolved],
        ];

        if ($rules !== null) {
            $gate['cellRules'] = $rules;
        }

        return $gate;
    }

    /**
     * Has anything already said where the value comes from?
     *
     * Read off the settings rather than the explicit attributes, because what
     * matters is whether the key will be *emitted*: a `value` a preset or an
     * action filled in as a default is still the thing the renderer would have
     * to fall back to, and a defaulted `field` beside it would win instead.
     */
    private function fieldIsSuperseded(): bool
    {
        $settings = $this->settings();

        foreach ($this->supersedesField() as $key) {
            if (array_key_exists($key, $settings)) {
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
