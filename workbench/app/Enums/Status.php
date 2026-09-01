<?php

declare(strict_types=1);

namespace Workbench\App\Enums;

use TamasLabs\Aura\Contracts\AuraIcon;
use TamasLabs\Aura\Contracts\AuraOption;
use TamasLabs\Aura\Contracts\AuraVariant;

/**
 * An employment status that names, colours and illustrates itself.
 *
 * Implementing the three optional interfaces is what lets one `->filterable()`
 * fill the filter dropdown and one `Badge::fromEnum()` colour the cell, from
 * the same enum the model casts to.
 */
enum Status: string implements AuraIcon, AuraOption, AuraVariant
{
    case Active = 'active';
    case OnLeave = 'on_leave';
    case Departed = 'departed';

    /**
     * {@inheritDoc}
     */
    public function label(): string
    {
        return match ($this) {
            self::Active => 'Active',
            self::OnLeave => 'On leave',
            self::Departed => 'Departed',
        };
    }

    /**
     * {@inheritDoc}
     */
    public function variant(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::OnLeave => 'warning',
            self::Departed => 'secondary',
        };
    }

    /**
     * {@inheritDoc}
     */
    public function icon(): string
    {
        return match ($this) {
            self::Active => 'check',
            self::OnLeave => 'clock',
            self::Departed => 'ban',
        };
    }
}
