<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Contracts;

/**
 * An enum whose cases carry a colour.
 *
 * Separate from {@see AuraOption} on purpose. A filter list needs a label and
 * nothing else, and requiring a colour from every enum that appears in one
 * would mean two dead methods on most of them. Implement this as well when the
 * enum is also rendered as a badge or a button.
 */
interface AuraVariant
{
    /**
     * Bootstrap colour name, or a key into the host app's `variants` registry.
     */
    public function variant(): string;
}
