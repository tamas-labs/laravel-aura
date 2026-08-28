<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use TamasLabs\Aura\Contracts\AuraOption;

/**
 * A backed enum that names itself — the filter list is built from these.
 */
enum Status: string implements AuraOption
{
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Aktív',
            self::Suspended => 'Felfüggesztett',
        };
    }
}
