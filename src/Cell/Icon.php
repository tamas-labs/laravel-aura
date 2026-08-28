<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasMapping;
use TamasLabs\Aura\Cell\Concerns\HasRoute;

/**
 * A glyph, optionally linking somewhere.
 *
 * The icon name is a key into the host app's `icons` config registry, which
 * Aura resolves into CSS classes — a Bootstrap Icons class name passed to
 * {@see self::make()} renders nothing. Pass CSS classes through
 * {@see self::class()} instead, which the contract accepts directly.
 *
 * This type has no `field` of its own, so a mapping selects on `key`; the key
 * is emitted for you when there is a mapping to select with.
 */
final class Icon extends CellConfig
{
    use HasElement;
    use HasMapping;
    use HasRoute;

    /**
     * @param  string|null  $icon  Key into the host app's `icons` registry.
     */
    public static function make(?string $icon = null): self
    {
        $config = new self;

        if ($icon !== null) {
            $config->set('icon', $icon);
        }

        return $config;
    }

    public function type(): string
    {
        return 'icon';
    }

    /**
     * Colour: a key into the host app's `variants` registry, or a Bootstrap
     * colour name.
     */
    public function variant(string $variant): self
    {
        return $this->set('variant', $variant);
    }

    /**
     * Alternative to {@see self::variant()}, resolved the same way.
     */
    public function color(string $color): self
    {
        return $this->set('color', $color);
    }

    /**
     * Size variant.
     *
     * @param  'xs'|'sm'|'md'|'lg'|'xl'  $size
     */
    public function size(string $size): self
    {
        return $this->set('size', $size);
    }

    /**
     * Accessible label, rendered as `aria-label`. Worth setting: an icon on its
     * own says nothing to a screen reader.
     */
    public function alt(string $alt): self
    {
        return $this->set('alt', $alt);
    }

    /**
     * Tooltip text.
     */
    public function title(string $title): self
    {
        return $this->set('title', $title);
    }

    protected function needsKey(array $settings): bool
    {
        return array_key_exists('mapping', $settings);
    }

    protected function requires(): array
    {
        return [[['icon'], ['class'], ['mapping']]];
    }
}
