<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Contracts;

/**
 * A `BackedEnum` that knows how to name itself in a table.
 *
 * Implementing this turns an enum cast into a filter dropdown: the column infers
 * its `elements` from the enum's cases, using `label()` for the text the user
 * reads and the case's own backing value for what travels in the request.
 *
 * Without it an enum still produces a filter list — the case names are used,
 * run through `Str::headline()` — so this interface buys wording, not
 * behaviour. Implement it as soon as the wording matters, which is usually
 * immediately.
 *
 * ```php
 * enum Status: string implements AuraOption
 * {
 *     case Active = 'active';
 *     case Suspended = 'suspended';
 *
 *     public function label(): string
 *     {
 *         return match ($this) {
 *             self::Active => __('Active'),
 *             self::Suspended => __('Suspended'),
 *         };
 *     }
 * }
 * ```
 */
interface AuraOption
{
    /**
     * Human-readable text for this case.
     */
    public function label(): string;
}
