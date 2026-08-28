<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Contracts;

/**
 * An enum whose cases carry an icon.
 *
 * Optional, like {@see AuraVariant}. The value is a key into the host app's
 * `icons` config registry — Aura resolves it into CSS classes on its side, so a
 * raw class name here renders nothing.
 */
interface AuraIcon
{
    /**
     * Key into the host app's `icons` registry.
     */
    public function icon(): string;
}
