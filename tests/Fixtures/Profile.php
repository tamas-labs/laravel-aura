<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The far side of a `HasOne` — the other relation sorting can resolve.
 */
final class Profile extends Model
{
    public $timestamps = false;

    protected $guarded = [];
}
