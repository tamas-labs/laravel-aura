<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use TamasLabs\Aura\Contracts\AuraOption;
use TamasLabs\Aura\Contracts\AuraVariant;

/**
 * An int-backed enum whose values are `0` and `1` — the one shape PHP cannot
 * keep as a map, since it normalises the string key `'0'` to the integer `0`
 * and `[0 => …, 1 => …]` is then a list. Everything built from it (`mapping`,
 * `elements`) has to reach the wire as a JSON object all the same.
 */
enum Flag: int implements AuraOption, AuraVariant
{
    case No = 0;
    case Yes = 1;

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return match ($this) {
            self::No => 'Nem',
            self::Yes => 'Igen',
        };
    }

    /**
     * {@inheritDoc}
     */
    public function variant(): string
    {
        return match ($this) {
            self::No => 'secondary',
            self::Yes => 'success',
        };
    }
}
