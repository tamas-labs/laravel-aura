<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table\Presets;

use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\Preset;

/**
 * A money column: currency-formatted, right-aligned, monospaced.
 *
 * The alignment and the monospace font are not decoration — they are what makes
 * a column of figures comparable down the page.
 *
 * Inference already does the first two from a `decimal` cast; this preset is for
 * the columns that hold money without saying so in a cast (an integer of minor
 * units, a database view, a computed column).
 */
final readonly class Money implements Preset
{
    public function __construct(private bool $monospace = true) {}

    /**
     * {@inheritDoc}
     */
    public function apply(Column $column): void
    {
        $column->default('currency', true)
            ->default('align', 'end')
            ->default('monospace', $this->monospace);
    }
}
