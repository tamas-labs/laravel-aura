<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table\Presets;

use BackedEnum;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\Inference;
use TamasLabs\Aura\Table\Preset;

/**
 * A column filtered by a fixed set of options, taken from a backed enum.
 *
 * The list comes from the enum's cases rather than from the loaded rows, which
 * is the difference that matters: derived options only ever offer the values
 * that happen to be on the current page, so a status nobody has yet is
 * unfilterable.
 */
final readonly class Options implements Preset
{
    /**
     * @param  class-string<BackedEnum>  $enum
     */
    public function __construct(private string $enum, private bool $center = true) {}

    /**
     * {@inheritDoc}
     */
    public function apply(Column $column): void
    {
        $column->default('filterable', true)
            ->default('elements', Inference::elementsFrom($this->enum));

        if ($this->center) {
            $column->default('align', 'center');
        }
    }
}
