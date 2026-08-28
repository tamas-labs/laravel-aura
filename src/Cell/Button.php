<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasFormatting;
use TamasLabs\Aura\Cell\Concerns\HasMapping;
use TamasLabs\Aura\Cell\Concerns\HasRoute;
use TamasLabs\Aura\Cell\Concerns\HasTypography;

/**
 * A button, usually one that navigates.
 *
 * Disabling one is presentation: `disabled` stops the click in this browser and
 * nothing else, so the route behind it still needs its own authorisation. The
 * per-row permission machinery arrives with the action layer; until then, guard
 * the route.
 */
final class Button extends CellConfig
{
    use HasElement;
    use HasFormatting;
    use HasMapping;
    use HasRoute;
    use HasTypography;

    /**
     * @param  string|null  $label  Fixed button label. Without one the button shows the row's value.
     */
    public static function make(?string $label = null): self
    {
        $config = new self;

        if ($label !== null) {
            $config->set('value', $label);
        }

        return $config;
    }

    public function type(): string
    {
        return 'button';
    }

    /**
     * Fixed button label.
     */
    public function value(string $value): self
    {
        return $this->set('value', $value);
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
     * Size variant.
     *
     * @param  'xs'|'sm'|'md'|'lg'|'xl'  $size
     */
    public function size(string $size): self
    {
        return $this->set('size', $size);
    }

    /**
     * Rounded corners.
     */
    public function rounded(bool $rounded = true): self
    {
        return $this->set('rounded', $rounded);
    }

    /**
     * Pill-shaped button.
     */
    public function pill(bool $pill = true): self
    {
        return $this->set('pill', $pill);
    }

    /**
     * An icon glyph — a key into the host app's `icons` registry.
     */
    public function icon(string $icon, ?string $position = null): self
    {
        $this->set('icon', $icon);

        return $position === null ? $this : $this->set('iconPosition', $position);
    }

    /**
     * Render the button disabled. Presentation only — see the class note.
     */
    public function disabled(bool $disabled = true): self
    {
        return $this->set('disabled', $disabled);
    }

    /**
     * Tooltip text.
     */
    public function title(string $title): self
    {
        return $this->set('title', $title);
    }

    /**
     * The `type` attribute of the `<button>` element.
     *
     * @param  'button'|'submit'|'reset'  $type
     */
    public function htmlType(string $type): self
    {
        return $this->set('htmlType', $type);
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
        return [[['field'], ['value'], ['route'], ['icon'], ['mapping']]];
    }
}
