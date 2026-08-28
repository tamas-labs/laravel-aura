<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The far side of a `BelongsTo`, so dotted fields have something to resolve to.
 */
final class Company extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
