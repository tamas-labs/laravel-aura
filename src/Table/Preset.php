<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

/**
 * A reusable bundle of column settings.
 *
 * A preset is what stops the same four calls from being repeated on every money
 * column in the application. It writes through {@see Column::default()}, so
 * anything the column sets explicitly still wins — whichever order the calls
 * were made in.
 *
 * ```php
 * Column::make('balance')->apply(new Money)->align('center');   // align wins
 * ```
 */
interface Preset
{
    /**
     * Write this preset's settings onto the column.
     */
    public function apply(Column $column): void;
}
