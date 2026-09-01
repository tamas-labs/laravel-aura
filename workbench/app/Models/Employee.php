<?php

declare(strict_types=1);

namespace Workbench\App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Workbench\App\Enums\Status;

/**
 * The row the demo table pages through.
 *
 * The casts are the point: every one of them is a column default the table
 * never has to spell out — the enum fills the filter dropdown, the decimal
 * brings `currency` and a right-hand alignment, the datetime brings a range
 * search.
 *
 * @property int $id
 * @property int $company_id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property Status $status
 * @property string $salary
 * @property int $workload
 * @property Carbon $hired_at
 * @property-read Company $company
 */
class Employee extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'salary' => 'decimal:2',
            'hired_at' => 'datetime',
        ];
    }
}
