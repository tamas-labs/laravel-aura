<?php

declare(strict_types=1);

use TamasLabs\Aura\Cell\Badge;
use TamasLabs\Aura\Cell\CellRules;
use TamasLabs\Aura\Cell\Condition;
use TamasLabs\Aura\Cell\ConditionalBuilder;
use TamasLabs\Aura\Cell\Reference;
use TamasLabs\Aura\Cell\Text;
use TamasLabs\Aura\Exceptions\InvalidDefinition;

/**
 * The branch list of a resolved config.
 *
 * @return array<array-key, mixed>
 */
function auraBranches(Badge|Reference|Text $config, string $field = 'status'): array
{
    return auraDigArray($config->resolve($field), 'if');
}

it('spells every operator the way Aura reads it', function (Condition $condition, string $operator, mixed $value): void {
    $branch = auraDigArray(auraBranches(
        Badge::make()->when($condition, fn (Badge $b): Badge => $b->variant('success'))
    ), 0);

    expect($branch[$operator])->toBe($value)
        ->and($branch['variant'])->toBe('success');
})->with([
    'eq' => [fn () => Condition::eq('active'), 'eq', 'active'],
    'ne' => [fn () => Condition::ne('active'), 'ne', 'active'],
    'gt' => [fn () => Condition::gt(10), 'gt', 10],
    'gte' => [fn () => Condition::gte(10), 'gte', 10],
    'lt' => [fn () => Condition::lt(10), 'lt', 10],
    'lte' => [fn () => Condition::lte(10), 'lte', 10],
    'between' => [fn () => Condition::between(1, 9), 'between', [1, 9]],
    'in' => [fn () => Condition::in(['a', 'b']), 'in', ['a', 'b']],
    'notIn' => [fn () => Condition::notIn(['a']), 'notIn', ['a']],
    'contains' => [fn () => Condition::contains('an'), 'contains', 'an'],
    'startsWith' => [fn () => Condition::startsWith('a'), 'startsWith', 'a'],
    'endsWith' => [fn () => Condition::endsWith('z'), 'endsWith', 'z'],
    'regex' => [fn () => Condition::regex('^a'), 'regex', '^a'],
    'null' => [fn () => Condition::isNull(), 'null', true],
    'notNull' => [fn () => Condition::notNull(), 'notNull', true],
    'empty' => [fn () => Condition::isEmpty(), 'empty', true],
    'notEmpty' => [fn () => Condition::notEmpty(), 'notEmpty', true],
    'true' => [fn () => Condition::isTrue(), 'true', true],
    'false' => [fn () => Condition::isFalse(), 'false', true],
]);

it('offers every operator the contract has, minus the aliases', function (): void {
    // 24 operator keys in the schema, 5 of them pure aliases (`neq`, `bigger`,
    // `biggerOrEqual`, `smaller`, `smallerOrEqual`).
    $factories = array_filter(
        (new ReflectionClass(Condition::class))->getMethods(ReflectionMethod::IS_STATIC),
        static fn (ReflectionMethod $method): bool => $method->isPublic(),
    );

    expect($factories)->toHaveCount(19);
});

it('always names the field the conditions read', function (): void {
    // Without a string `key` Aura skips the conditions and applies the base
    // config instead — fail-open, and never what the definition meant.
    $resolved = Badge::make()->when(Condition::eq('active'), fn (Badge $b): Badge => $b->variant('success'))
        ->resolve('status');

    expect($resolved['key'])->toBe('status');
});

it('reads another field when told to', function (): void {
    $resolved = Reference::make()
        ->on('archived_at')
        ->when(Condition::notNull(), fn (Reference $r): Reference => $r->italic())
        ->resolve('last_name');

    expect($resolved['key'])->toBe('archived_at')
        ->and($resolved['field'])->toBe('last_name');
});

it('refuses conditions with no field to read', function (): void {
    CellRules::make()
        ->when(Condition::isTrue(), fn (CellRules $r): CellRules => $r->background('#eee'))
        ->resolve('');
})->throws(InvalidDefinition::class, 'needs the field its conditions read');

it('keeps the branches in the order they were added', function (): void {
    $branches = auraBranches(
        Badge::make()
            ->when(Condition::eq('active'), fn (Badge $b): Badge => $b->variant('success'))
            ->when(Condition::notNull(), fn (Badge $b): Badge => $b->variant('secondary'))
    );

    expect(auraDigArray($branches, 0)['eq'])->toBe('active')
        ->and(auraDigArray($branches, 1))->toHaveKey('notNull');
});

it('emits no else when the definition offers none, because that is how a cell hides', function (): void {
    // resolve-conditional-config.ts:94 — no branch matched and no `else` means
    // the cell renders empty. Adding an `else` would take that away.
    $resolved = Badge::make()->when(Condition::eq('active'), fn (Badge $b): Badge => $b->variant('success'))
        ->resolve('status');

    expect($resolved)->not->toHaveKey('else');
});

it('applies the else branch when nothing matched', function (): void {
    $resolved = Badge::make()
        ->when(Condition::eq('active'), fn (Badge $b): Badge => $b->variant('success'))
        ->otherwise(fn (Badge $b): Badge => $b->variant('secondary'))
        ->resolve('status');

    expect(auraDigArray($resolved, 'else')['variant'])->toBe('secondary');
});

it('leaves the key out of a leaf branch, where it means something else', function (): void {
    // In a leaf branch `key` is the route placeholder source (stripBranchProps),
    // not the condition selector — emitting it there would be a quiet surprise.
    $branch = auraDigArray(auraBranches(
        Badge::make()->when(Condition::eq('active'), fn (Badge $b): Badge => $b->variant('success'))
    ), 0);

    expect($branch)->not->toHaveKey('key');
});

it('carries the key into a branch that has conditions of its own', function (): void {
    $branch = auraDigArray(auraBranches(
        Badge::make()->when(
            Condition::notNull(),
            fn (Badge $b): Badge => $b->on('balance')
                ->when(Condition::gt(0), fn (Badge $inner): Badge => $inner->variant('success')),
        )
    ), 0);

    expect($branch['key'])->toBe('balance')
        ->and(auraDigArray($branch, 'if', 0)['gt'])->toBe(0);
});

it('counts how deep the conditions nest', function (int $levels, int $depth): void {
    expect(auraNested($levels)->depth())->toBe($depth);
})->with([[1, 1], [3, 3], [5, 5]]);

it('accepts nesting as deep as Aura resolves', function (): void {
    expect(auraNested(5)->resolve('status'))->toHaveKey('if');
});

it('refuses nesting Aura would silently truncate', function (): void {
    // MAX_RECURSION_DEPTH = 5, and the overflow is reported to Aura's error
    // store and nowhere the user looks.
    auraNested(6)->resolve('status');
})->throws(InvalidDefinition::class, 'silently drops the rest');

it('collects the fields a numeric condition reads', function (): void {
    $config = Reference::make()
        ->when(Condition::gt(1000), fn (Reference $r): Reference => $r->color('success'))
        ->when(Condition::eq('x'), fn (Reference $r): Reference => $r->color('dark'));

    expect($config->numericFields('balance'))->toBe(['balance']);
});

it('collects nothing from conditions Aura compares as strings', function (): void {
    $config = Reference::make()->when(Condition::eq('active'), fn (Reference $r): Reference => $r->italic());

    expect($config->numericFields('status'))->toBe([]);
});

it('collects the field a nested numeric condition reads', function (): void {
    $config = Reference::make()->when(
        Condition::notNull(),
        fn (Reference $r): Reference => $r->on('balance')
            ->when(Condition::lt(0), fn (Reference $inner): Reference => $inner->color('danger')),
    );

    expect($config->numericFields('status'))->toBe(['balance']);
});

it('collects the fields cell rules compare numerically', function (): void {
    $config = Reference::make()->rules(
        CellRules::make()
            ->on('balance')
            ->when(Condition::lt(0), fn (CellRules $r): CellRules => $r->background('#fee'))
    );

    expect($config->numericFields('last_name'))->toBe(['balance']);
});

it('waives the required keys once a branch can supply them', function (): void {
    // The schema does the same: `value` stops being required as soon as `if` is
    // present, because the matching branch brings it.
    $resolved = Text::make()
        ->when(Condition::notNull(), fn (Text $t): Text => $t->value('yes'))
        ->resolve('archived_at');

    expect($resolved['type'])->toBe('static');

    assertMatchesAuraConfig($resolved, 'archived_at');
});

/**
 * A badge whose conditions nest exactly this many levels.
 */
function auraNested(int $levels): Badge
{
    $attach = function (Badge $target, int $remaining) use (&$attach): void {
        if ($remaining <= 0) {
            return;
        }

        $target->when(Condition::notNull(), function (Badge $branch) use ($remaining, &$attach): void {
            $branch->variant('success');

            /** @var callable(Badge, int): void $attach */
            $attach($branch, $remaining - 1);
        });
    };

    $badge = Badge::make();

    $attach($badge, $levels);

    return $badge;
}

it('agrees with Aura about how deep is too deep', function (): void {
    expect(ConditionalBuilder::MAX_DEPTH)->toBe(5);
});
