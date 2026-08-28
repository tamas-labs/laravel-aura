<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

/**
 * A backed enum that does *not* implement the package interface, so the
 * fallback labelling has something to prove itself on.
 */
enum Tier: string
{
    case FreeTrial = 'free_trial';
    case Paid = 'paid';
}
