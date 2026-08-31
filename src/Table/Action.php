<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

/**
 * One of the four resource actions Aura builds a link for on its own.
 *
 * Aura's response preprocessor recognises a field named `{action}_{suffix}` in
 * a header cell and, finding no configuration for it, generates one: the glyph
 * from the host app's icon registry, and the route from the resource base the
 * *browser* holds (`urlParameter`). Nothing about it travels from here — the
 * server states which actions the column offers and stops.
 *
 * ```php
 * Column::actions('id', Action::show(), Action::edit(), Action::destroy())
 * ```
 *
 * That is the whole of convention mode, and it is deliberately thin: the route
 * base lives on the client, so the server has nothing to say about the URL. The
 * moment anything is customised — a route of your own, a different glyph, a
 * modal id — the column has to emit a full configuration instead, and that is
 * what escalation does.
 *
 * The four prefixes are Laravel's resource verbs and mean what they do there:
 *
 * | Action      | Route                | Renders as     |
 * | ----------- | -------------------- | -------------- |
 * | `create()`  | `{base}/create`      | link           |
 * | `show()`    | `{base}/{key}`       | link           |
 * | `edit()`    | `{base}/{key}/edit`  | link           |
 * | `destroy()` | `{base}/{key}/destroy` | **modal trigger** (`destroyModal`) |
 *
 * `create` is the odd one: its route carries no placeholder, yet Aura renders
 * it in every row. That is how the client behaves, and this package reproduces
 * it rather than correcting it — a create button belongs in the toolbar, and a
 * table that wants one there should not ask for this action.
 */
final class Action
{
    /**
     * The action prefixes Aura's preprocessors treat as resource routes.
     *
     * Any other prefix — `status_icon`, `switch_user_icon` — is a plain glyph
     * with no route, which is a rendering decision and not an action.
     *
     * @var list<string>
     */
    private const array PREFIXES = ['create', 'edit', 'show', 'destroy'];

    /**
     * The three suffixes that trigger auto-generation.
     *
     * All three are recognised here, though only `icon` can be produced today:
     * a hand-written `edit_link` is the same convention spelled by hand, and
     * the guards in {@see DefinitionBuilder} have to see it as one.
     *
     * @var list<string>
     */
    private const array SUFFIXES = ['icon', 'link', 'button'];

    /** The suffix convention mode emits. */
    private const string SUFFIX = 'icon';

    private function __construct(private readonly string $prefix) {}

    /**
     * The create form — `{base}/create`.
     *
     * Rendered in every row, placeholder or not; see the class docblock.
     */
    public static function create(): self
    {
        return new self('create');
    }

    /**
     * The detail page — `{base}/{key}`.
     */
    public static function show(): self
    {
        return new self('show');
    }

    /**
     * The edit form — `{base}/{key}/edit`.
     */
    public static function edit(): self
    {
        return new self('edit');
    }

    /**
     * Deletion — a trigger for Aura's built-in confirmation modal, not a link.
     */
    public static function destroy(): self
    {
        return new self('destroy');
    }

    /**
     * The item field this action occupies, and the name the browser generates
     * its configuration under.
     */
    public function field(): string
    {
        return $this->prefix.'_'.self::SUFFIX;
    }

    /**
     * Is this field name one Aura will turn into a resource route?
     *
     * Read by the definition builder, which refuses such a name anywhere but an
     * action column: `columnConfigs` is a flat map keyed by field, so a second
     * `edit_icon` in the header does not get a second entry — it gets the first
     * one's route, built with the first cell's key.
     */
    public static function isActionField(string $field): bool
    {
        $position = strrpos($field, '_');

        if ($position === false) {
            return false;
        }

        return in_array(substr($field, $position + 1), self::SUFFIXES, true)
            && in_array(substr($field, 0, $position), self::PREFIXES, true);
    }
}
