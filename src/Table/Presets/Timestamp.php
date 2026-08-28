<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table\Presets;

use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\Preset;

/**
 * A date or datetime column, searched by range rather than by term.
 *
 * The range input is the point: a term search over a formatted date matches the
 * string the browser renders, not the value the database holds, so it finds the
 * wrong rows or none at all. It is only offered on a column that has a search
 * input to begin with.
 */
final readonly class Timestamp implements Preset
{
    public function __construct(private bool $withTime = true) {}

    /**
     * A date-only column.
     */
    public static function date(): self
    {
        return new self(withTime: false);
    }

    /**
     * {@inheritDoc}
     */
    public function apply(Column $column): void
    {
        $column->default($this->withTime ? 'datetime' : 'date', true);

        if ($column->isSearchable()) {
            $column->default('between', true);
        }
    }
}
