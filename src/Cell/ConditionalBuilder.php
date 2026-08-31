<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use Illuminate\Support\Traits\Macroable;
use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Response\NumericFields;
use TamasLabs\Aura\Table\Column;

/**
 * The half of a cell configuration that can vary per row.
 *
 * Aura merges the first matching `if` branch over the base configuration, or
 * `else` when none matched — and renders *nothing at all* when neither happens.
 * That last case is the mechanism, not an accident: a branch list with no
 * `else` is how a cell is hidden for some rows.
 *
 * Two rules are enforced here rather than left to the browser, because both
 * fail quietly there:
 *
 * - **`if` is never emitted without a `key`.** Aura skips the conditions
 *   entirely when `key` is not a string and applies the base config instead
 *   (`resolve-conditional-config.ts:76`) — the wrong direction to fail in when
 *   the condition is the thing deciding whether a row may see something. The
 *   schema claims `key` defaults to the column key; nothing in Aura fills that
 *   default in.
 * - **Nesting deeper than five levels throws.** Aura stops at
 *   `MAX_RECURSION_DEPTH = 5` and silently renders the truncated config.
 */
abstract class ConditionalBuilder
{
    use Macroable;

    /**
     * Aura's `MAX_RECURSION_DEPTH`. A chain of this many conditional configs
     * still resolves; the next one down is dropped.
     */
    public const MAX_DEPTH = 5;

    /** Structural keys {@see self::merge()} refuses, because they are shape, not content. */
    private const STRUCTURAL = ['type', 'key', 'if', 'else'];

    /** @var array<string, mixed> */
    protected array $attributes = [];

    /**
     * Filled by inference and by presets; overridden by anything explicit.
     *
     * @var array<string, mixed>
     */
    protected array $inferred = [];

    /** @var list<array{condition: Condition, branch: static}> */
    protected array $branches = [];

    /** @var static|null */
    protected ?self $else = null;

    /** The item field the conditions read. Defaults to the field the config is attached to. */
    protected ?string $conditionKey = null;

    /**
     * Final so {@see self::newBranch()} can build a branch of whatever subclass
     * it is called on: a subclass that took constructor arguments would make
     * `new static` unsafe. The types are built by their own named factories.
     */
    final protected function __construct() {}

    /**
     * A branch: when the condition holds, the callback's settings are merged
     * over this configuration.
     *
     * Branches are evaluated in the order they are added, and the first match
     * wins — so put the specific case before the general one.
     *
     * @param  callable(static): mixed  $branch
     */
    public function when(Condition $condition, callable $branch): static
    {
        $configured = $this->newBranch();

        $branch($configured);

        $this->branches[] = ['condition' => $condition, 'branch' => $configured];

        return $this;
    }

    /**
     * What to apply when no branch matched.
     *
     * Leaving this out is meaningful: with branches and no `otherwise()`, a row
     * matching none of them renders an empty cell. That is the supported way to
     * hide a cell per row.
     *
     * @param  callable(static): mixed  $branch
     */
    public function otherwise(callable $branch): static
    {
        $configured = $this->newBranch();

        $branch($configured);

        $this->else = $configured;

        return $this;
    }

    /**
     * The item field the conditions are evaluated against.
     *
     * Defaults to the field the configuration is attached to, which is usually
     * what you want; name another field to branch on something the column does
     * not itself display.
     */
    public function on(string $field): static
    {
        $this->conditionKey = $field;

        return $this;
    }

    /**
     * Set several contract keys this builder has no methods for — the escape
     * hatch, spelled the same way as {@see Column::merge()}.
     *
     * Deliberately unvalidated beyond the structural keys. Every column config
     * in the schema declares `additionalProperties: true` and requires only
     * `type`, so running what lands here past the schema would wave almost
     * anything through and give a false sense of having checked.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidDefinition When a structural key is passed.
     */
    public function merge(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            $this->set($key, $value);
        }

        return $this;
    }

    /**
     * Set one contract key explicitly. Wins over anything inferred.
     *
     * The structural keys are refused here rather than in {@see self::merge()},
     * because this is where every path ends up: `merge()` delegates to it, and
     * it is public in its own right. A hand-written `key` would silently win
     * over the one the conditions are emitted with — the emitted configuration
     * is `['type' => …] + settings + conditionals`, and the settings come
     * first — which is the fail-open case {@see self::conditionals()} exists to
     * prevent.
     *
     * @throws InvalidDefinition When the key decides shape rather than content.
     */
    public function set(string $key, mixed $value): static
    {
        if (in_array($key, self::STRUCTURAL, true)) {
            throw InvalidDefinition::rawStructuralKey($key);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Fill a key only if the caller has not set it.
     */
    public function default(string $key, mixed $value): static
    {
        if (! array_key_exists($key, $this->attributes)) {
            $this->inferred[$key] = $value;
        }

        return $this;
    }

    /**
     * How many conditional configurations deep this one goes: 0 when it has no
     * branches at all, 1 when it has branches that are themselves plain.
     */
    public function depth(): int
    {
        if ($this->branches === [] && $this->else === null) {
            return 0;
        }

        $deepest = 0;

        foreach ($this->branches as $entry) {
            $deepest = max($deepest, $entry['branch']->depth());
        }

        if ($this->else !== null) {
            $deepest = max($deepest, $this->else->depth());
        }

        return $deepest + 1;
    }

    /**
     * Item fields this configuration compares numerically.
     *
     * Collected so the response can hand Aura real numbers for them: Aura's
     * numeric operators require `typeof === 'number'` on both sides, and a
     * Laravel `decimal` cast serialises as a string. See
     * {@see NumericFields}.
     *
     * @return list<string>
     */
    public function numericFields(string $defaultKey): array
    {
        $key = $this->conditionKey ?? $defaultKey;
        $fields = [];

        foreach ($this->branches as $entry) {
            if ($entry['condition']->isNumeric()) {
                $fields[] = $key;
            }

            foreach ($entry['branch']->numericFields($key) as $nested) {
                $fields[] = $nested;
            }
        }

        if ($this->else !== null) {
            foreach ($this->else->numericFields($key) as $nested) {
                $fields[] = $nested;
            }
        }

        return array_values(array_unique($fields));
    }

    /**
     * Is this configuration conditional at all?
     */
    public function isConditional(): bool
    {
        return $this->branches !== [] || $this->else !== null;
    }

    /**
     * The field {@see self::on()} named, if the caller named one.
     *
     * Read by the table, which has to know whether the conditions can fall back
     * to the column's own field — a multi-field column has none to offer.
     *
     * @internal
     */
    public function conditionField(): ?string
    {
        return $this->conditionKey;
    }

    /**
     * A fresh instance of this builder, to be configured as one branch.
     *
     * A branch is a partial configuration — the schema waives the "one of these
     * is required" rules as soon as `if` or `else` is present, because the
     * branch supplies what the base left out.
     */
    protected function newBranch(): static
    {
        return new static;
    }

    /**
     * The settings this builder contributes, before the conditional keys.
     *
     * @return array<string, mixed>
     */
    protected function settings(): array
    {
        return array_merge($this->inferred, $this->attributes);
    }

    /**
     * `key`, `if` and `else`, ready to merge into the emitted configuration.
     *
     * @param  string  $defaultKey  The field this configuration is attached to.
     * @return array<string, mixed>
     *
     * @throws InvalidDefinition When the nesting is deeper than Aura resolves.
     */
    protected function conditionals(string $defaultKey): array
    {
        if (! $this->isConditional()) {
            return [];
        }

        $depth = $this->depth();

        if ($depth > self::MAX_DEPTH) {
            throw InvalidDefinition::conditionsTooDeep($depth, self::MAX_DEPTH);
        }

        $key = $this->conditionKey ?? $defaultKey;

        if ($key === '') {
            throw InvalidDefinition::conditionsWithoutKey();
        }

        $emitted = ['key' => $key];

        if ($this->branches !== []) {
            $emitted['if'] = array_map(
                static fn (array $entry): array => $entry['branch']->asBranch($entry['condition'], $key),
                $this->branches,
            );
        }

        if ($this->else !== null) {
            $emitted['else'] = $this->else->asBranch(null, $key);
        }

        return $emitted;
    }

    /**
     * One entry of the `if` array, or the `else` object: the operator plus the
     * settings applied when it matches.
     *
     * `key` only travels into a branch that needs it — one that carries its own
     * conditions, or one told to read a different field. In a leaf branch `key`
     * means something else entirely (the route placeholder source,
     * `stripBranchProps`), so emitting it there would be a quiet surprise.
     *
     * @return array<string, mixed>
     */
    protected function asBranch(?Condition $condition, string $inheritedKey): array
    {
        $branch = $condition instanceof Condition
            ? [$condition->operator => $condition->value]
            : [];

        $branch += $this->settings();

        if ($this->isConditional()) {
            return $branch + $this->conditionals($this->conditionKey ?? $inheritedKey);
        }

        if ($this->conditionKey !== null) {
            $branch['key'] = $this->conditionKey;
        }

        return $branch;
    }
}
