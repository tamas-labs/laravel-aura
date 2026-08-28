<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * The far side of the relation, with a cast of its own — so inference has
 * something to find one level away from the table's own model.
 */
final class TypedCompany extends Model
{
    public $timestamps = false;

    protected $table = 'companies';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['tier' => Tier::class];
    }
}
