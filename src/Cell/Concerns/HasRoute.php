<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Cell\Concerns;

use TamasLabs\Aura\Exceptions\InvalidDefinition;

/**
 * A URL template resolved per row.
 *
 * Aura's `resolveRoute` does three things in order: substitutes `{placeholder}`
 * tokens from the row, **replaces every remaining dot with a slash**, and
 * prefixes the host app's `siteName`. So `users.{id}.edit` becomes
 * `/users/5/edit` — and the absolute URL Laravel's `route()` helper returns
 * becomes `/https://app/example/com/users/5/edit`, silently. Only a relative,
 * dotted or slashed path belongs here, which is what {@see self::route()}
 * enforces.
 */
trait HasRoute
{
    /** A placeholder Aura will actually substitute: its own regex is `\{([\w.]+)\}`. */
    private const PLACEHOLDER = '/\{([^}]*)\}/';

    abstract public function set(string $key, mixed $value): static;

    /**
     * The URL template, e.g. `users.{id}.edit` or `/users/{id}/edit`.
     *
     * A `{placeholder}` is filled from the row by name, dots included
     * (`{company.id}` works). Beware a value that itself contains a dot: it
     * becomes a slash like any other, so an email address makes a mess of the
     * path.
     *
     * @throws InvalidDefinition When the template is absolute, or carries a
     *                           placeholder Aura would not substitute.
     */
    public function route(string $route): static
    {
        self::assertRelative($route);
        self::assertPlaceholdersResolvable($route);

        return $this->set('route', $route);
    }

    /**
     * @throws InvalidDefinition
     */
    private static function assertRelative(string $route): void
    {
        if (str_contains($route, '://') || str_starts_with($route, '//')) {
            throw InvalidDefinition::absoluteRoute($route);
        }
    }

    /**
     * A placeholder outside Aura's `[\w.]+` alphabet is never matched, so it
     * survives into the rendered URL as literal `{…}` text.
     *
     * @throws InvalidDefinition
     */
    private static function assertPlaceholdersResolvable(string $route): void
    {
        if (preg_match_all(self::PLACEHOLDER, $route, $matches) === 0) {
            return;
        }

        foreach ($matches[1] as $placeholder) {
            if (preg_match('/^[\w.]+$/', $placeholder) !== 1) {
                throw InvalidDefinition::unresolvablePlaceholder($route, $placeholder);
            }
        }
    }
}
