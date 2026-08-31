<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A model written the way models were written before native return types.
 *
 * The point of the fixture is {@see self::company()}: a relation declared with
 * a `@return` docblock and nothing else. Refusing to follow those would be a
 * tighter guard and a much more expensive one, so `Support\Relations` allows a
 * method with no declared return type through — which is exactly what needs a
 * test of its own.
 */
final class LegacyUser extends Model
{
    /**
     * Set by {@see self::fullName()} so a test can prove it is never called.
     */
    public static bool $called = false;

    public $timestamps = false;

    protected $table = 'users';

    protected $guarded = [];

    /**
     * An untyped relation — the compatibility case.
     *
     * @return BelongsTo<Company, $this>
     */
    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * The caller's own method, declaring a return type that is not a relation.
     * It records being run, so a test can assert that it is not.
     */
    public function fullName(): string
    {
        self::$called = true;

        return 'never reached through a dotted field';
    }

    /**
     * Public but needs an argument, so it could not be called blind anyway.
     */
    public function scopeNamed(string $name): string
    {
        return $name;
    }

    /**
     * Not public, which is also how an `Attribute` accessor is written.
     *
     * @return BelongsTo<Company, $this>
     */
    protected function employer(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
