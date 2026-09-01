<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Response;

use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use TamasLabs\Aura\Exceptions\InvalidDefinition;

/**
 * The hidden per-row flags a permission-gated cell is switched on by.
 *
 * A gated configuration is emitted as a condition over a field the rows do not
 * otherwise carry — `_allowed_edit_icon` for an `edit_icon` action — with no
 * `else` beneath it. Aura renders the branch when the flag is `true` and
 * *nothing at all* when no branch matched and there is no `else`
 * (`resolve-conditional-config.ts:94`), so the flag is what decides whether the
 * cell appears. This class is the other half: it puts the flag in every row.
 *
 * Three properties are deliberate, and each one is a way this could otherwise
 * fail quietly:
 *
 * - **The flag is written for every row, false included.** A missing field
 *   reads as `undefined` in the browser, `true` is an exact comparison
 *   (`fieldValue === true`), and the cell is hidden — the same outcome as a
 *   denial. That is the safe direction, and it is also indistinguishable from
 *   a bug, so the flag is always there to be looked at.
 * - **It is written as a real `bool`.** `true` and `false` are exact operators:
 *   `1` is not `true`, and a `tinyint` column or a `"1"` from a driver would
 *   silently deny. Whatever the callback returns is cast.
 * - **The decision is prepared once per page.** The callback registered by
 *   `allowedWhenAll()` receives the whole page and returns the per-row test, so
 *   a lookup that needs a query runs one, not one per row.
 *
 * ## This is not authorisation
 *
 * A hidden cell is a hidden cell. The row is still in the payload, the route is
 * still in `columnConfigs`, and anyone can type the URL. The gate belongs on
 * the route as well — ideally reading the same policy — and this only keeps the
 * table from offering an action that would then be refused.
 *
 * @internal
 */
final class RowPermissions
{
    /**
     * What a permission field's name is built from.
     *
     * Underscore-prefixed so it cannot be mistaken for a column, and derived
     * from the field it guards so the payload says which cell it belongs to.
     */
    public const PREFIX = '_allowed_';

    /**
     * Keyed by the emitted field name; each entry turns a page into a per-row test.
     *
     * @var array<string, Closure(Collection<int, Model>): mixed>
     */
    private array $factories = [];

    private function __construct() {}

    /**
     * An empty set, to be filled from the columns.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * The row field guarding a cell configuration attached to `$field`.
     *
     * The dots of a relation path become underscores: Aura reads a dotted name
     * as a walk into nested objects (`resolveValue`), and `_allowed_company.name`
     * would send it looking for a `name` inside an `_allowed_company` that does
     * not exist — every row denied, nothing logged.
     */
    public static function fieldFor(string $field): string
    {
        return self::PREFIX.str_replace('.', '_', $field);
    }

    /**
     * Register the gate guarding `$field`.
     *
     * @param  Closure(Collection<int, Model>): mixed  $factory  Given the page, returns the per-row test.
     *
     * @throws InvalidDefinition When two cells would share one flag.
     */
    public function add(string $field, Closure $factory): self
    {
        $name = self::fieldFor($field);

        if (array_key_exists($name, $this->factories)) {
            throw InvalidDefinition::duplicatePermissionField($name, $field);
        }

        $this->factories[$name] = $factory;

        return $this;
    }

    /**
     * Does this table gate anything at all? The whole pass is skipped when not.
     */
    public function isEmpty(): bool
    {
        return $this->factories === [];
    }

    /**
     * The emitted flag names, in registration order.
     *
     * @return list<string>
     */
    public function fields(): array
    {
        return array_keys($this->factories);
    }

    /**
     * Write every flag into every row.
     *
     * The models and the rows are the same page in the same order — the payload
     * is `array_values(array_map(…))` over the paginator's items — so they are
     * matched by position. The models are what the callbacks see: a policy
     * wants the object, not the array it flattened to.
     *
     * @param  list<Model>  $models
     * @param  list<mixed>  $items
     * @return list<mixed>
     *
     * @throws InvalidDefinition When a batched callback did not return a test.
     */
    public function apply(array $models, array $items): array
    {
        if ($this->factories === []) {
            return $items;
        }

        // An Eloquent collection, not a plain one: a batched gate almost always
        // wants `modelKeys()` for the `whereIn` it is there to run once.
        $page = new Collection($models);

        foreach ($this->factories as $name => $factory) {
            $decide = $factory($page);

            if (! is_callable($decide)) {
                throw InvalidDefinition::permissionResolverShape($name, get_debug_type($decide));
            }

            foreach ($models as $index => $model) {
                $item = $items[$index] ?? null;

                if (! is_array($item)) {
                    continue;
                }

                // Cast, never pass through: `true` is an exact comparison in
                // Aura, so a `1` from a tinyint would deny the row.
                $item[$name] = (bool) $decide($model);

                $items[$index] = $item;
            }
        }

        return $items;
    }
}
