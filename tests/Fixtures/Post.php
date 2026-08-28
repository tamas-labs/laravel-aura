<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The far side of a `HasMany`, which sorting must refuse rather than guess at.
 */
final class Post extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
