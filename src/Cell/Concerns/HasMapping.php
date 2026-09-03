<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell\Concerns;

use TamasLabs\Aura\Cell\Condition;
use TamasLabs\Aura\Support\JsonMap;

/**
 * A value → settings lookup, applied per row.
 *
 * Aura resolves the mapping **after** the `if` / `else` branches are flattened,
 * and merges the matched entry over the result — so a mapping entry beats a
 * branch, not the other way round. Reach for it when one field selects between
 * a handful of fixed presentations; reach for {@see Condition}
 * when the rule is a comparison rather than a lookup.
 *
 * Which field is looked up depends on the type: `field` if the config has one,
 * otherwise the condition `key` — except on `link` and `button`, where `key`
 * names the route source instead and only `field` selects.
 */
trait HasMapping
{
    abstract public function set(string $key, mixed $value): static;

    /**
     * The lookup table, keyed by the field's value.
     *
     * Emitted as a JSON object whatever the keys look like — see
     * {@see JsonMap}, which is what keeps a `0` / `1` map from going out as a
     * two-element array that Aura refuses.
     *
     * @param  array<array-key, array<string, mixed>>  $mapping
     */
    public function mapping(array $mapping): static
    {
        return $this->set('mapping', JsonMap::from($mapping));
    }
}
