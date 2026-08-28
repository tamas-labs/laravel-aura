<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasTypography;
use TamasLabs\Aura\Response\NumericFields;

/**
 * A progress bar.
 *
 * The value has to reach the browser as a real number — a bar reading a Laravel
 * `decimal` cast would otherwise get a string. The table coerces the fields
 * named here for you; see {@see NumericFields}.
 *
 * ```php
 * Column::make('completion')->as(
 *     Progress::make()->max(100)->thresholds(['danger' => [0, 33], 'success' => [67, 100]])
 * );
 * ```
 */
final class Progress extends CellConfig
{
    use HasElement;
    use HasTypography;

    /**
     * @param  string|null  $field  Item field holding the value. Defaults to the column's own field.
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
     * A stacked bar, from segments each reading their own field.
     *
     * @param  list<array<string, mixed>>  $bars
     */
    public static function stacked(array $bars): self
    {
        $config = new self;
        $config->set('stacked', true);
        $config->set('bars', $bars);

        return $config;
    }

    public function type(): string
    {
        return 'progress';
    }

    /**
     * A fixed value, instead of reading one from the row.
     */
    public function value(float|int $value): self
    {
        return $this->set('value', $value);
    }

    /**
     * Upper bound of the scale: a number, or the name of the field holding it.
     * Defaults to 100.
     */
    public function max(float|int|string $max): self
    {
        return $this->set('max', $max);
    }

    /**
     * Lower bound of the scale. Defaults to 0.
     */
    public function min(float|int|string $min): self
    {
        return $this->set('min', $min);
    }

    /**
     * Bar colour.
     */
    public function variant(string $variant): self
    {
        return $this->set('variant', $variant);
    }

    /**
     * Track height, e.g. `20px`.
     */
    public function height(string $height): self
    {
        return $this->set('height', $height);
    }

    /**
     * Striped bar, optionally animated.
     */
    public function striped(bool $striped = true, bool $animated = false): self
    {
        $this->set('striped', $striped);

        return $animated ? $this->set('animated', true) : $this;
    }

    /**
     * Bar label: `true` for the value itself, or fixed text.
     */
    public function label(bool|string $label = true, ?string $position = null): self
    {
        $this->set('label', $label);

        return $position === null ? $this : $this->set('labelPosition', $position);
    }

    /**
     * Show the raw value in the label.
     */
    public function showValue(bool $show = true): self
    {
        return $this->set('showValue', $show);
    }

    /**
     * Show the percentage in the label, to this many decimal places.
     */
    public function showPercent(bool $show = true, ?int $decimals = null): self
    {
        $this->set('showPercent', $show);

        return $decimals === null ? $this : $this->set('decimals', $decimals);
    }

    /**
     * Text wrapped around the label.
     */
    public function affixes(?string $prefix = null, ?string $suffix = null): self
    {
        if ($prefix !== null) {
            $this->set('prefix', $prefix);
        }

        return $suffix === null ? $this : $this->set('suffix', $suffix);
    }

    /**
     * Bootstrap colour → inclusive `[min, max]` range. The first range holding
     * the value sets the bar's colour.
     *
     * @param  array<string, array{0: float|int, 1: float|int}>  $thresholds
     */
    public function thresholds(array $thresholds): self
    {
        return $this->set('thresholds', $thresholds);
    }

    /**
     * A `"min-max"` keyed lookup of bar settings.
     *
     * Progress has its own range-keyed mapping semantics, so the keys are
     * ranges (`"0-25"`), not values.
     *
     * @param  array<string, array<string, mixed>>  $mapping
     */
    public function mapping(array $mapping): self
    {
        return $this->set('mapping', $mapping);
    }

    /**
     * The bar's own fields, on top of anything the conditions read: a bar is a
     * number by definition, and Aura will not draw one from a string.
     *
     * @return list<string>
     */
    public function numericFields(string $defaultKey): array
    {
        $fields = parent::numericFields($defaultKey);

        foreach (['field', 'max', 'min'] as $key) {
            $value = $this->attributes[$key] ?? null;

            if (is_string($value)) {
                $fields[] = $value;
            }
        }

        if (! array_key_exists('field', $this->attributes) && ! array_key_exists('stacked', $this->attributes)) {
            $fields[] = $defaultKey;
        }

        foreach ($this->barFields() as $field) {
            $fields[] = $field;
        }

        return array_values(array_unique($fields));
    }

    protected function readsField(): bool
    {
        return true;
    }

    /**
     * A stacked bar reads its `bars`; a `field` beside them would make Aura
     * draw one plain bar instead.
     */
    protected function supersedesField(): array
    {
        return ['fields', 'stacked'];
    }

    protected function requires(): array
    {
        return [[['field'], ['value'], ['stacked', 'bars']]];
    }

    /**
     * The fields the segments of a stacked bar read.
     *
     * @return list<string>
     */
    private function barFields(): array
    {
        $bars = $this->attributes['bars'] ?? null;

        if (! is_array($bars)) {
            return [];
        }

        $fields = [];

        foreach ($bars as $bar) {
            $field = is_array($bar) ? ($bar['field'] ?? null) : null;

            if (is_string($field)) {
                $fields[] = $field;
            }
        }

        return $fields;
    }
}
