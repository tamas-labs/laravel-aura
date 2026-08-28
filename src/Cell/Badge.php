<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use BackedEnum;
use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasFormatting;
use TamasLabs\Aura\Cell\Concerns\HasMapping;
use TamasLabs\Aura\Cell\Concerns\HasTypography;
use TamasLabs\Aura\Contracts\AuraIcon;
use TamasLabs\Aura\Contracts\AuraOption;
use TamasLabs\Aura\Contracts\AuraVariant;

/**
 * A coloured pill — the usual rendering of a status column.
 *
 * The colour normally comes from a lookup rather than a condition, which
 * {@see self::fromEnum()} builds straight out of a backed enum: the labels come
 * from {@see AuraOption::label()} and the variants and icons from the optional
 * {@see AuraVariant} and
 * {@see AuraIcon} the same enum may implement.
 *
 * ```php
 * Column::make('status')->filterable()->as(Badge::fromEnum(Status::class));
 * ```
 */
final class Badge extends CellConfig
{
    use HasElement;
    use HasFormatting;
    use HasMapping;
    use HasTypography;

    /**
     * @param  string|null  $field  Item field driving the badge. Defaults to the column's own field.
     */
    public static function make(?string $field = null): self
    {
        $config = new self;

        if ($field !== null) {
            $config->set('field', $field);
        }

        return $config;
    }

    /**
     * A badge per enum case: label, and colour and icon where the enum offers
     * them.
     *
     * @param  class-string<BackedEnum>  $enum
     */
    public static function fromEnum(string $enum, ?string $field = null): self
    {
        return self::make($field)->mapping(EnumPresentation::badges($enum));
    }

    public function type(): string
    {
        return 'badge';
    }

    /**
     * Fixed label text, instead of the field's value.
     */
    public function value(string $value): self
    {
        return $this->set('value', $value);
    }

    /**
     * Base Bootstrap colour, rendered as `text-bg-{variant}`.
     */
    public function variant(string $variant): self
    {
        return $this->set('variant', $variant);
    }

    /**
     * Render as a pill.
     */
    public function pill(bool $pill = true): self
    {
        return $this->set('pill', $pill);
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
     * An icon glyph beside the label — a key into the host app's `icons`
     * registry, not a CSS class.
     */
    public function icon(string $icon, ?string $position = null): self
    {
        $this->set('icon', $icon);

        return $position === null ? $this : $this->set('iconPosition', $position);
    }

    /**
     * The badge shown for a truthy value.
     *
     * @param  array<string, mixed>  $badge
     */
    public function whenTrue(array $badge): self
    {
        return $this->set('trueValue', $badge);
    }

    /**
     * The badge shown for a falsy value.
     *
     * @param  array<string, mixed>  $badge
     */
    public function whenFalse(array $badge): self
    {
        return $this->set('falseValue', $badge);
    }

    /**
     * Render the badge when the numeric value is 0. On by default.
     */
    public function showZero(bool $show = true): self
    {
        return $this->set('showZero', $show);
    }

    /**
     * Cap the displayed number; past it the badge shows `{max}{suffix}`.
     */
    public function maxValue(int $max, string $suffix = '+'): self
    {
        return $this->set('maxValue', $max)->set('suffix', $suffix);
    }

    /**
     * Text prepended to the label, e.g. `#`.
     */
    public function prefix(string $prefix): self
    {
        return $this->set('prefix', $prefix);
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
        return [[['field'], ['value'], ['mapping'], ['trueValue'], ['falseValue']]];
    }
}
