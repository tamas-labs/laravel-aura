<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasFormatting;
use TamasLabs\Aura\Cell\Concerns\HasMapping;
use TamasLabs\Aura\Cell\Concerns\HasRoute;
use TamasLabs\Aura\Cell\Concerns\HasTypography;

/**
 * An anchor whose text is the row's value and whose target is a per-row route.
 *
 * ```php
 * Column::make('email')->as(Link::make()->route('users.{id}.show'));
 * ```
 *
 * Note the split roles of `key` here: on a link it names the field the route
 * placeholders read, not the mapping selector — a mapping on a link selects on
 * `field` alone (`resolve-mapping-config.ts`).
 */
final class Link extends CellConfig
{
    use HasElement;
    use HasFormatting;
    use HasMapping;
    use HasRoute;
    use HasTypography;

    /**
     * @param  string|null  $field  Item field whose value becomes the link text.
     */
    public static function make(?string $field = null): self
    {
        $config = new self;

        if ($field !== null) {
            $config->set('field', $field);
        }

        return $config;
    }

    public function type(): string
    {
        return 'link';
    }

    /**
     * Fixed link text, instead of the field's value.
     */
    public function value(string $value): self
    {
        return $this->set('value', $value);
    }

    /**
     * Anchor `target`. Setting `_blank` also sets a safe `rel`, since the
     * opened page would otherwise get a handle on this one.
     */
    public function target(string $target): self
    {
        $this->set('target', $target);

        return $target === '_blank' ? $this->default('rel', 'noopener noreferrer') : $this;
    }

    /**
     * Anchor `rel`.
     */
    public function rel(string $rel): self
    {
        return $this->set('rel', $rel);
    }

    /**
     * Tooltip text.
     */
    public function title(string $title): self
    {
        return $this->set('title', $title);
    }

    /**
     * Colour: a key into the host app's `variants` registry, or a Bootstrap
     * colour name.
     */
    public function variant(string $variant): self
    {
        return $this->set('variant', $variant);
    }

    protected function readsField(): bool
    {
        return true;
    }

    protected function formats(): bool
    {
        return true;
    }

    protected function requires(): array
    {
        return [[['field'], ['value'], ['route'], ['mapping']]];
    }
}
