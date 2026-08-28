<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasFormatting;
use TamasLabs\Aura\Cell\Concerns\HasTypography;

/**
 * Fixed text — Aura's `static` type. Named `Text` because `Static` is a
 * reserved word in PHP.
 *
 * This renders `value`, **not** the row's data: `renderStaticNode` formats
 * `config.value` and never looks at the item. Row data with the same formatter
 * chain is {@see Reference}; the use for this type is a label that is the same
 * on every row, or one that a branch chooses:
 *
 * ```php
 * Text::make('—')
 *     ->when(Condition::notNull(), fn (Text $t) => $t->set('value', 'yes'));
 * ```
 */
final class Text extends CellConfig
{
    use HasElement;
    use HasFormatting;
    use HasTypography;

    /**
     * @param  string|null  $value  The text to render. Optional only when branches supply it.
     */
    public static function make(?string $value = null): self
    {
        $config = new self;

        if ($value !== null) {
            $config->set('value', $value);
        }

        return $config;
    }

    public function type(): string
    {
        return 'static';
    }

    /**
     * The text to render.
     */
    public function value(string $value): self
    {
        return $this->set('value', $value);
    }

    protected function requires(): array
    {
        return [[['value']]];
    }
}
