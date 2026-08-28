<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell\Concerns;

use TamasLabs\Aura\Cell\CellRules;

/**
 * Type and colour settings on the rendered content.
 *
 * Present on seven of the nine column configs, always with the same meaning.
 * These style the content, not the `<td>` around it — for the cell itself see
 * {@see CellRules}.
 */
trait HasTypography
{
    abstract public function set(string $key, mixed $value): static;

    /**
     * Text colour: a Bootstrap theme colour name, or CSS colour syntax.
     */
    public function color(string $color): static
    {
        return $this->set('color', $color);
    }

    /**
     * Background colour behind the content.
     */
    public function background(string $color): static
    {
        return $this->set('background', $color);
    }

    /**
     * Alignment of the content inside the cell.
     *
     * @param  'start'|'center'|'end'  $align
     */
    public function align(string $align): static
    {
        return $this->set('align', $align);
    }

    /**
     * CSS font size: a `px`/`rem`/`em`/`%` length, or a keyword such as `large`.
     */
    public function fontSize(string $size): static
    {
        return $this->set('fontSize', $size);
    }

    /**
     * CSS font weight: a multiple of 100 from 100 to 900, or a keyword.
     */
    public function fontWeight(int|string $weight): static
    {
        return $this->set('fontWeight', $weight);
    }

    /**
     * Italic text.
     */
    public function italic(bool $italic = true): static
    {
        return $this->set('italic', $italic);
    }

    /**
     * Reset italics — useful in a branch that has to undo one.
     */
    public function normal(bool $normal = true): static
    {
        return $this->set('normal', $normal);
    }

    /**
     * CSS line height: a positive number, a length, or `normal`.
     */
    public function lineHeight(float|int|string $lineHeight): static
    {
        return $this->set('lineHeight', $lineHeight);
    }

    /**
     * A Bootstrap text utility class — it has to start with `text-`.
     *
     * Framework-bound by nature: this is a Bootstrap class name travelling over
     * the contract, and it means nothing to a differently styled front end.
     */
    public function text(string $utility): static
    {
        return $this->set('text', $utility);
    }
}
