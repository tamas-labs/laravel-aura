<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The same `users` table as {@see User}, but with casts declared.
 *
 * Separate on purpose: the query tests assert on raw column values, and casting
 * `balance` to a decimal string or `created_at` to a Carbon instance would
 * change what those tests compare without changing what they are about.
 */
final class TypedUser extends Model
{
    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * @return BelongsTo<TypedCompany, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(TypedCompany::class, 'company_id');
    }

    /**
     * @return HasOne<Profile, $this>
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class, 'user_id');
    }

    /**
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => Status::class,
            'balance' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }
}
