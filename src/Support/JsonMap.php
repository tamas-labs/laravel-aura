<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Support;

use stdClass;
use TamasLabs\Aura\Table\TableBlueprint;

/**
 * A value → configuration lookup, in the one PHP shape that survives
 * `json_encode()` as a JSON object.
 *
 * PHP turns the array key `'0'` into the integer `0`, so a map built from an
 * int-backed enum — the classic `0 => 'No', 1 => 'Yes'` flag — comes out of
 * `json_encode()` as `[{"label":"No"},{"label":"Yes"}]`. Aura types every such
 * slot as `z.record(z.string(), …)`, which refuses an array outright: the body
 * validation aborts and the table never renders. It bites exactly when the keys
 * form the sequence `0…n-1`; a `1, 2` enum happens to encode as an object
 * already, which is why the failure looks arbitrary from the outside.
 *
 * A `stdClass` is the cast, deliberately and not a class of this package's own:
 * the finished definition is cached (see {@see TableBlueprint}),
 * and an entry holding a class that a later deploy renames or drops comes back
 * from the cache as an incomplete object. `stdClass` cannot go stale.
 *
 * @internal
 */
final class JsonMap
{
    /**
     * The map as an object, so its keys stay keys.
     *
     * @param  array<array-key, mixed>  $map
     */
    public static function from(array $map): stdClass
    {
        return (object) $map;
    }
}
