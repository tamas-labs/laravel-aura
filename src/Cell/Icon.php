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

    /**
     * A mapping selects on `key` — and so, uniquely among the linking
     * renderers, does the link itself: `renderIconNode` wraps the glyph in an
     * `<a>` only when `route` **and** `key` are both present, while `link`,
     * `button` and `modal` need the route alone. Without this an icon with a
     * route renders as a bare glyph and navigates nowhere, in silence.
     */
    protected function needsKey(array $settings): bool
    {
        return array_key_exists('mapping', $settings) || array_key_exists('route', $settings);
    }

    /**
     * The mapping selector wins when there is one, because that is the only
     * role in which the *value* of `key` is read; the link only needs it to be
     * there. Otherwise the key names the row field the route is built from,
     * falling back to the column's key exactly as Aura's preprocessor does for
     * a route with no placeholder of its own (`create`).
     */
    protected function keyFor(string $field, array $settings, array $headerCell): string
    {
        if (array_key_exists('mapping', $settings)) {
            return $field;
        }

        $placeholder = self::routePlaceholder($settings);

        if ($placeholder !== null) {
            return $placeholder;
        }

        $cellKey = $headerCell['key'] ?? null;

        return is_string($cellKey) && $cellKey !== '' ? $cellKey : $field;
    }

    protected function requires(): array
    {
        return [[['icon'], ['class'], ['mapping']]];
    }
}
