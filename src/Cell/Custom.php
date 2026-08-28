<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasFormatting;
use TamasLabs\Aura\Cell\Concerns\HasMapping;
use TamasLabs\Aura\Cell\Concerns\HasTypography;

/**
 * The escape hatch, for a rendering none of the other eight types describes.
 *
 * Deliberately thin. `renderer` and `callback` name functions in the host app's
 * JavaScript registry (`config.renderers` / `config.callbacks`), and PHP cannot
 * check that either exists — a typo here is a cell that renders nothing, found
 * in the browser. `template` is the safe option: a string with `{placeholder}`
 * tokens filled from the row and from {@see self::params()}.
 *
 * The coupling runs the other way from everything else in this package: the
 * name written here is a promise about the front-end build, so keep the two in
 * the same review.
 */
final class Custom extends CellConfig
{
    use HasElement;
    use HasFormatting;
    use HasMapping;
    use HasTypography;

    /**
     * A template string; `{placeholder}` tokens come from the row and from
     * {@see self::params()}.
     */
    public static function template(string $template): self
    {
        return (new self)->set('template', $template);
    }

    /**
     * A function in the host app's `renderers` registry, returning a node.
     */
    public static function renderer(string $name): self
    {
        return (new self)->set('renderer', $name);
    }

    /**
     * A function in the host app's `callbacks` registry, returning text.
     */
    public static function callback(string $name): self
    {
        return (new self)->set('callback', $name);
    }

    public function type(): string
    {
        return 'custom';
    }

    /**
     * Item field holding the value.
     */
    public function field(string $field): self
    {
        return $this->set('field', $field);
    }

    /**
     * Several item fields, passed to the renderer.
     *
     * @param  list<string>  $fields
     */
    public function fields(array $fields): self
    {
        return $this->set('fields', $fields);
    }

    /**
     * Fixed text.
     */
    public function value(string $value): self
    {
        return $this->set('value', $value);
    }

    /**
     * Extra values available to the template and the registered functions.
     *
     * @param  array<string, mixed>  $params
     */
    public function params(array $params): self
    {
        return $this->set('params', $params);
    }

    protected function formats(): bool
    {
        return true;
    }

    protected function requires(): array
    {
        return [[['renderer'], ['callback'], ['template'], ['field'], ['fields'], ['value']]];
    }
}
