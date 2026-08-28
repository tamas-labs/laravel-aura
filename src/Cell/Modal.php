<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasRoute;

/**
 * A trigger that opens a modal instead of navigating.
 *
 * `destroyModal` is Aura's built-in delete confirmation, which is why
 * {@see self::destroy()} exists — the delete action is the one every table has.
 *
 * ```php
 * Column::make('delete', '')->as(
 *     Modal::destroy()->route('users.{id}.destroy')->icon('trash', 'danger')
 * );
 * ```
 *
 * The trigger itself is either one of the two shorthands (an icon or a button)
 * or a nested configuration of any type via {@see self::content()}.
 */
final class Modal extends CellConfig
{
    use HasElement;
    use HasRoute;

    /** Aura's built-in delete confirmation. */
    public const DESTROY = 'destroyModal';

    private ?CellConfig $content = null;

    /**
     * @param  string  $id  Identifier of the modal to open.
     */
    public static function make(string $id): self
    {
        return (new self)->set('id', $id);
    }

    /**
     * The built-in delete confirmation.
     */
    public static function destroy(): self
    {
        return self::make(self::DESTROY);
    }

    public function type(): string
    {
        return 'modal';
    }

    /**
     * Icon-trigger shorthand: a key into the host app's `icons` registry.
     */
    public function icon(string $icon, ?string $variant = null): self
    {
        $this->set('icon', $icon);

        return $variant === null ? $this : $this->set('variant', $variant);
    }

    /**
     * Button-trigger shorthand: the button's variant, and its label.
     */
    public function button(string $variant, ?string $label = null): self
    {
        $this->set('button', $variant);

        return $label === null ? $this : $this->set('value', $label);
    }

    /**
     * A trigger built from any other cell configuration.
     */
    public function content(CellConfig $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Trigger size.
     *
     * @param  'xs'|'sm'|'md'|'lg'|'xl'  $size
     */
    public function size(string $size): self
    {
        return $this->set('size', $size);
    }

    /**
     * Accessible label on the trigger.
     */
    public function alt(string $alt): self
    {
        return $this->set('alt', $alt);
    }

    /**
     * Tooltip text on the trigger.
     */
    public function title(string $title): self
    {
        return $this->set('title', $title);
    }

    /**
     * Anchor `target`, for a link trigger.
     */
    public function target(string $target): self
    {
        return $this->set('target', $target);
    }

    /**
     * The nested trigger, resolved into the `content` key.
     *
     * @param  array<string, mixed>  $headerCell
     */
    protected function prepare(string $field, array $headerCell): void
    {
        if ($this->content instanceof CellConfig) {
            $this->set('content', $this->content->resolve($field, $headerCell));
        }
    }

    protected function requires(): array
    {
        return [
            [['id']],
            [['icon'], ['button'], ['content']],
        ];
    }
}
