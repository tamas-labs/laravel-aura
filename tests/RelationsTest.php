<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use TamasLabs\Aura\Support\Relations;
use TamasLabs\Aura\Tests\Fixtures\LegacyUser;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;

beforeEach(function (): void {
    LegacyUser::$called = false;
});

it('resolves a relation that declares its return type', function (): void {
    expect(Relations::on(new TypedUser, 'company'))->toBeInstanceOf(BelongsTo::class);
});

it('resolves a relation that only has a docblock, as most older models do', function (): void {
    // The compatibility guarantee: requiring a native `Relation` return type
    // would be a tighter guard and would stop inferring through every relation
    // written before PHP 7.4 made return types worth using.
    expect(Relations::on(new LegacyUser, 'company'))->toBeInstanceOf(BelongsTo::class);
});

it('refuses a framework method, however harmless the name looks', function (string $method): void {
    // These are the reason the guard exists. `Model::delete()`, `push()` and
    // company are untyped, so nothing about their signature separates them from
    // an untyped relation — only the class that declares them does.
    expect(Relations::on(new TypedUser, $method))->toBeNull();
})->with(['delete', 'push', 'save', 'refresh', 'touch', 'toArray', 'getKey']);

it('refuses a method of the model that returns something other than a relation', function (): void {
    expect(Relations::on(new LegacyUser, 'fullName'))->toBeNull()
        // And it decided that without running it — which is the whole point.
        ->and(LegacyUser::$called)->toBeFalse();
});

it('refuses a method it could not call blind', function (string $method): void {
    expect(Relations::on(new LegacyUser, $method))->toBeNull();
})->with([
    'needs an argument' => 'scopeNamed',
    'is not public' => 'employer',
    'does not exist' => 'nonesuch',
]);
