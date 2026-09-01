<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The `BelongsTo` side of the demo, so the table has a relation to sort,
 * search and filter through.
 *
 * @property int $id
 * @property string $name
 * @property string $city
 */
class Company extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }
}
