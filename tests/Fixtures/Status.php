<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use TamasLabs\Aura\Contracts\AuraIcon;
use TamasLabs\Aura\Contracts\AuraOption;
use TamasLabs\Aura\Contracts\AuraVariant;

/**
 * A backed enum that names, colours and illustrates itself — the case where all
 * three optional interfaces are implemented.
 */
enum Status: string implements AuraIcon, AuraOption, AuraVariant
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

    /**
     * {@inheritDoc}
     */
    public function variant(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Suspended => 'danger',
        };
    }

    /**
     * {@inheritDoc}
     */
    public function icon(): string
    {
        return match ($this) {
            self::Active => 'check',
            self::Suspended => 'ban',
        };
    }
}
