<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell;

use TamasLabs\Aura\Cell\Concerns\HasElement;

/**
 * Conditional styling of the `<td>` itself — and, as `rowRules`, of the `<tr>`.
 *
 * The same shape serves both: a base set of formatting options, plus `if` /
 * `else` branches carrying more of the same. What it cannot do is hide
 * anything. `rowRules` is formatting only (`row-rules.zod.ts`), so a row the
 * user must not see has to be excluded by the query, not styled away.
 *
 * ```php
 * CellRules::make()
 *     ->on('status')
 *     ->when(Condition::eq('suspended'), fn (CellRules $r) => $r->background('#fee'));
 * ```
 */
final class CellRules extends ConditionalBuilder
{
    use HasElement;

    /**
     * An empty rule set, ready to be given branches.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Cell background colour.
     */
    public function background(string $color): static
    {
        return $this->set('background', $color);
    }

    /**
     * Cell text colour.
     */
    public function color(string $color): static
    {
        return $this->set('color', $color);
    }

    /**
     * Draw the top border.
     */
    public function borderTop(bool $border = true): static
    {
        return $this->set('borderTop', $border);
    }

    /**
     * Draw the bottom border.
     */
    public function borderBottom(bool $border = true): static
    {
        return $this->set('borderBottom', $border);
    }

    /**
     * Draw the left border.
     */
    public function borderLeft(bool $border = true): static
    {
        return $this->set('borderLeft', $border);
    }

    /**
     * Draw the right border.
     */
    public function borderRight(bool $border = true): static
    {
        return $this->set('borderRight', $border);
    }

    /**
     * Border colour.
     */
    public function borderColor(string $color): static
    {
        return $this->set('borderColor', $color);
    }

    /**
     * Border width, e.g. `3px`.
     */
    public function borderWidth(string $width): static
    {
        return $this->set('borderWidth', $width);
    }

    /**
     * Inner padding, e.g. `8px 16px`.
     */
    public function padding(string $padding): static
    {
        return $this->set('padding', $padding);
    }

    /**
     * Cell opacity, between 0 and 1.
     */
    public function opacity(float $opacity): static
    {
        return $this->set('opacity', $opacity);
    }

    /**
     * The finished rule set.
     *
     * @param  string  $defaultKey  The field the conditions read unless told otherwise.
     * @return array<string, mixed>
     */
    public function resolve(string $defaultKey): array
    {
        return $this->settings() + $this->conditionals($defaultKey);
    }
}
