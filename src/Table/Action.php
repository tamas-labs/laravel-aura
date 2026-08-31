<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use Illuminate\Support\Facades\Route;
use TamasLabs\Aura\Cell\Button;
use TamasLabs\Aura\Cell\CellConfig;
use TamasLabs\Aura\Cell\Concerns\HasRoute;
use TamasLabs\Aura\Cell\Icon;
use TamasLabs\Aura\Cell\Link;
use TamasLabs\Aura\Cell\Modal;
use TamasLabs\Aura\Exceptions\InvalidDefinition;

/**
 * One of the four resource actions Aura builds a link for on its own.
 *
 * Aura's response preprocessor recognises a field named `{action}_{suffix}` in
 * a header cell and, finding no configuration for it, generates one: the glyph
 * from the host app's icon registry, and the route from the resource base the
 * *browser* holds (`urlParameter`). Convention mode is the server stating which
 * actions a column offers and stopping there.
 *
 * ```php
 * Column::actions('id', Action::show(), Action::edit(), Action::destroy())
 * ```
 *
 * The four prefixes are Laravel's resource verbs and mean what they do there:
 *
 * | Action      | Route                  | Renders as     |
 * | ----------- | ---------------------- | -------------- |
 * | `create()`  | `{base}/create`        | link           |
 * | `show()`    | `{base}/{key}`         | link           |
 * | `edit()`    | `{base}/{key}/edit`    | link           |
 * | `destroy()` | `{base}/{key}/destroy` | **modal trigger** (`destroyModal`) |
 *
 * `create` is the odd one: its route carries no placeholder, yet Aura renders
 * it in every row. That is how the client behaves, and this package reproduces
 * it rather than correcting it — a create button belongs in the toolbar.
 *
 * ## Escalation
 *
 * The convention stops at the browser's own defaults. The moment anything is
 * customised — a route, a glyph, a colour, a label, a tooltip, a modal id — the
 * server can no longer leave the field to be generated, because a generated
 * configuration would not carry the customisation. So the action **escalates**:
 * it emits the whole `body.columnConfigs` entry itself, and the preprocessor
 * skips the field on finding one already there.
 *
 * ```php
 * Action::edit()->title('Edit this user')      // escalated
 * Action::destroy()->asButton()->variant('danger')
 * ```
 *
 * The call surface does not change, only the payload. What does change is that
 * an escalated action needs a route the *server* can build, which means the
 * table has to say what its resource is — see {@see AuraTable::$resource} —
 * unless the action names a route of its own.
 *
 * Two things escalation cannot reproduce exactly, both because the registries
 * live in the browser:
 *
 * - **An icon's classes.** The generated config carries the resolved
 *   `class: ['fas', 'fa-pen', 'text-primary']`; the escalated one carries
 *   `icon` and `variant`, which Aura's `normalizeIconConfigs` resolves through
 *   the same registries in the same pass. Different payload, identical render.
 * - **A button's Bootstrap variant.** The generated config reads
 *   `variants[prefix]`, falling back to `variants.primary` and then the literal
 *   `primary`. The server has no registry to read, so an escalated button is
 *   `primary` unless {@see self::variant()} says otherwise. This is the one
 *   place where escalating changes what the user sees, and only when the host
 *   app registered a variant under the action's own name.
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
     * The three suffixes that trigger auto-generation, and so the three shapes
     * an action can take.
     *
     * @var list<string>
     */
    private const array SUFFIXES = ['icon', 'link', 'button'];

    /** The suffix an action takes unless {@see self::asLink()} or {@see self::asButton()} says otherwise. */
    private const string DEFAULT_SUFFIX = 'icon';

    /** What a generated button falls back to when the registry offers nothing. */
    private const string FALLBACK_VARIANT = 'primary';

    /** Aura's placeholder alphabet, and Laravel's optional-parameter marker. */
    private const string PARAMETER = '/\{(\w+)\??\}/';

    private string $suffix = self::DEFAULT_SUFFIX;

    /**
     * Everything set on the trigger. Any entry escalates.
     *
     * @var array<string, mixed>
     */
    private array $settings = [];

    /** A route of the caller's own, already validated. */
    private ?string $route = null;

    /** The one placeholder of that route still waiting for the column key. */
    private ?string $parameter = null;

    /** The modal an escalated `destroy` opens. */
    private string $modal = Modal::DESTROY;

    private bool $escalated = false;

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
     * Render as a glyph. The default, and the only shape that needs no width.
     */
    public function asIcon(): self
    {
        return $this->as('icon');
    }

    /**
     * Render as a text link. Aura's own generated label is the bare prefix
     * (`edit`), which {@see self::label()} is there to replace.
     */
    public function asLink(): self
    {
        return $this->as('link');
    }

    /**
     * Render as a Bootstrap button.
     */
    public function asButton(): self
    {
        return $this->as('button');
    }

    /**
     * The glyph — a key into the host app's `icons` registry, not a CSS class.
     */
    public function icon(string $icon): self
    {
        return $this->set('icon', $icon);
    }

    /**
     * The colour. On an icon a key into the `variants` registry; on a button a
     * Bootstrap variant name, used directly as `btn-{variant}`.
     */
    public function variant(string $variant): self
    {
        return $this->set('variant', $variant);
    }

    /**
     * The visible text of a link or a button.
     */
    public function label(string $label): self
    {
        return $this->set('value', $label);
    }

    /**
     * Tooltip text.
     */
    public function title(string $title): self
    {
        return $this->set('title', $title);
    }

    /**
     * Accessible label. Worth setting on an icon: a glyph on its own says
     * nothing to a screen reader.
     */
    public function alt(string $alt): self
    {
        return $this->set('alt', $alt);
    }

    /**
     * A route of this action's own, as a relative path: `users/{id}/edit`.
     *
     * Stricter than {@see HasRoute::route()} in
     * one way — a dot is refused here. Aura turns every dot into a slash, so a
     * Laravel route *name* passed by mistake (`users.edit`) would resolve to
     * `/users/edit`: a real URL, missing the identifier, failing silently.
     * {@see self::routeName()} is the supported way to use a route name.
     *
     * @throws InvalidDefinition When the path is absolute or carries a dot.
     */
    public function route(string $route): self
    {
        self::assertUsableRoute($route);

        $this->route = $route;
        $this->parameter = null;
        $this->escalated = true;

        return $this;
    }

    /**
     * A named Laravel route, resolved through the router.
     *
     * The route's URI is read as it was registered — `admin/users/{user}/edit`
     * — never through `route()`, whose absolute URL Aura would mangle into
     * `/https://app/example/com/...`. Parameters you name are substituted; the
     * one left over becomes the placeholder Aura fills from the row, under the
     * action column's key.
     *
     * ```php
     * Action::edit()->routeName('admin.users.edit');
     * Action::show()->routeName('companies.users.show', ['company' => $company->id]);
     * ```
     *
     * A value that is itself a `{placeholder}` is passed through untouched, so
     * a second row field can fill a second parameter.
     *
     * @param  array<string, string|int>  $parameters
     *
     * @throws InvalidDefinition When the route is unknown, leaves more than one
     *                           parameter open, or resolves to a path Aura cannot use.
     */
    public function routeName(string $name, array $parameters = []): self
    {
        $route = Route::getRoutes()->getByName($name);

        if ($route === null) {
            throw InvalidDefinition::unknownRoute($name);
        }

        $uri = $route->uri();

        preg_match_all(self::PARAMETER, $uri, $matches);

        $open = array_values(array_diff($matches[1], array_keys($parameters)));

        if (count($open) > 1) {
            throw InvalidDefinition::ambiguousRoute($name, $open);
        }

        foreach ($parameters as $parameter => $value) {
            $uri = str_replace(['{'.$parameter.'}', '{'.$parameter.'?}'], (string) $value, $uri);
        }

        $uri = str_replace('?}', '}', $uri);

        self::assertUsableRoute($uri, $name);

        $this->route = $uri;
        $this->parameter = $open[0] ?? null;
        $this->escalated = true;

        return $this;
    }

    /**
     * The modal a `destroy` opens. Escalates on its own, since the generated
     * configuration can only ever name Aura's built-in one.
     */
    public function modal(string $id): self
    {
        $this->modal = $id;
        $this->escalated = true;

        return $this;
    }

    /**
     * Any other key the trigger's configuration accepts — `size`, `target`,
     * `rounded`, a `data-*` attribute. The escape hatch, and it escalates like
     * every other customisation.
     *
     * On a `destroy` these land on the *trigger*, not on the modal around it:
     * the trigger is what the user sees and styles.
     */
    public function set(string $key, mixed $value): self
    {
        $this->settings[$key] = $value;
        $this->escalated = true;

        return $this;
    }

    /**
     * The item field this action occupies, and the name the browser would
     * generate its configuration under.
     */
    public function field(): string
    {
        return $this->prefix.'_'.$this->suffix;
    }

    /**
     * Has anything been customised — and so does this action have to emit its
     * own configuration rather than leave the field to the browser?
     */
    public function isEscalated(): bool
    {
        return $this->escalated;
    }

    /**
     * The `body.columnConfigs` entry for an escalated action.
     *
     * @param  string  $columnKey  The action column's key — the route placeholder.
     * @param  array<string, mixed>  $headerCell
     * @return array<string, mixed>
     *
     * @throws InvalidDefinition When the route cannot be built.
     */
    public function resolve(string $columnKey, ?string $resource, array $headerCell = []): array
    {
        return $this->config($columnKey, $resource)->resolve($this->field(), $headerCell);
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

    /**
     * A resource base becomes a route, and fails the same two ways.
     *
     * @throws InvalidDefinition
     */
    public static function assertUsableResource(string $resource): void
    {
        if (str_contains($resource, '://') || str_starts_with($resource, '//')) {
            throw InvalidDefinition::invalidResource($resource, 'absolute');
        }

        if (str_contains($resource, '.')) {
            throw InvalidDefinition::invalidResource($resource, 'dotted');
        }
    }

    /**
     * The trigger, wrapped in a modal when the action is a `destroy`.
     *
     * The split mirrors Aura's own generated configuration exactly: the route
     * sits on the modal and the glyph in its `content`, because it is the modal
     * that navigates — over AJAX, into the confirmation dialog — and the
     * trigger that is merely clicked.
     *
     * @throws InvalidDefinition
     */
    private function config(string $columnKey, ?string $resource): CellConfig
    {
        $route = $this->routeFor($columnKey, $resource);
        $trigger = $this->trigger();

        if ($this->prefix !== 'destroy') {
            return $trigger->route($route);
        }

        return Modal::make($this->modal)->route($route)->content($trigger);
    }

    /**
     * The glyph, link or button itself, with the browser's own defaults filled
     * in first so that anything explicit still wins.
     */
    private function trigger(): Icon|Link|Button
    {
        $label = ucfirst($this->prefix);

        $trigger = match ($this->suffix) {
            'link' => Link::make()->default('value', $this->prefix),
            'button' => Button::make()
                ->default('value', $this->prefix)
                ->default('variant', self::FALLBACK_VARIANT),
            // The icon and the variant are registry keys, and Aura's generator
            // uses the prefix for both; `normalizeIconConfigs` resolves them
            // into the same `class` the generated config would have carried.
            default => Icon::make($this->prefix)
                ->default('variant', $this->prefix)
                ->default('alt', $label)
                ->default('title', $label),
        };

        return $trigger->merge($this->settings);
    }

    /**
     * The route this action navigates to: the caller's own, or the resource
     * convention spelled out server-side.
     *
     * @throws InvalidDefinition When neither is available.
     */
    private function routeFor(string $columnKey, ?string $resource): string
    {
        if ($this->route !== null) {
            return $this->parameter === null
                ? $this->route
                : str_replace('{'.$this->parameter.'}', '{'.$columnKey.'}', $this->route);
        }

        if ($resource === null) {
            throw InvalidDefinition::actionNeedsResource($this->field());
        }

        $base = trim($resource, '/');

        return match ($this->prefix) {
            'create' => $base.'/create',
            'show' => $base.'/{'.$columnKey.'}',
            'edit' => $base.'/{'.$columnKey.'}/edit',
            default => $base.'/{'.$columnKey.'}/destroy',
        };
    }

    private function as(string $suffix): self
    {
        $this->suffix = $suffix;

        return $this;
    }

    /**
     * Aura resolves a route by substituting `{placeholder}`s, turning every
     * remaining dot into a slash and prefixing the host's `siteName`. Both
     * failures below are therefore silent: an absolute URL becomes a path, and
     * a dot becomes a directory separator.
     *
     * @throws InvalidDefinition
     */
    private static function assertUsableRoute(string $route, ?string $name = null): void
    {
        if (str_contains($route, '://') || str_starts_with($route, '//')) {
            throw InvalidDefinition::absoluteRoute($route);
        }

        if (str_contains($route, '.')) {
            throw InvalidDefinition::dottedActionRoute($route, $name);
        }
    }
}
