<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell\Concerns;

/**
 * The two keys every cell configuration has, whatever it renders.
 *
 * `class` and `style` land on whichever element the type draws — the anchor of
 * a link, the pill of a badge, the `<i>` of an icon — so the meaning is the
 * type's, and the plumbing is identical in all ten places it appears. Both are
 * framework-bound by nature: a Bootstrap class name travelling over the
 * contract means nothing to a differently styled front end.
 *
 * For the `<td>` around the content rather than the content itself, see
 * `CellRules`.
 */
trait HasElement
{
    abstract public function set(string $key, mixed $value): static;

    /**
     * CSS classes on the rendered element.
     *
     * @param  string|list<string>  $class
     */
    public function class(string|array $class): static
    {
        return $this->set('class', $class);
    }

    /**
     * Inline CSS on the rendered element.
     */
    public function style(string $style): static
    {
        return $this->set('style', $style);
    }
}
