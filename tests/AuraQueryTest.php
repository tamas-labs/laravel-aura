<?php

declare(strict_types=1);

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use TamasLabs\Aura\Exceptions\UnsupportedPaginator;
use TamasLabs\Aura\Exceptions\UnsupportedRelation;
use TamasLabs\Aura\Query\AuraQuery;
use TamasLabs\Aura\Query\FieldPermissions;
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;
use TamasLabs\Aura\Tests\Fixtures\Company;
use TamasLabs\Aura\Tests\Fixtures\Post;
use TamasLabs\Aura\Tests\Fixtures\Profile;
use TamasLabs\Aura\Tests\Fixtures\User;

/**
 * Everything the query tests are allowed to touch.
 */
function queryPermissions(): FieldPermissions
{
    return new FieldPermissions(
        sortable: ['last_name', 'status', 'balance', 'created_at', 'company.name', 'profile.nickname', 'posts.title', 'company.owner.name'],
        searchable: ['first_name', 'last_name', 'balance', 'created_at', 'company.name'],
        filterable: ['status', 'company.name'],
        globalSearch: ['first_name', 'last_name', 'company.name'],
    );
}

/**
 * @param  array<string, mixed>  $payload
 */
function auraRequest(array $payload): AuraRequest
{
    return AuraRequest::fromArray($payload + ['page' => 1, 'paginate' => 50], queryPermissions());
}

/**
 * @return list<string>
 */
function lastNamesFor(AuraRequest $request): array
{
    return array_values(array_map(
        static fn (mixed $name): string => is_scalar($name) ? (string) $name : '',
        AuraQuery::apply(User::query(), $request)->pluck('last_name')->all(),
    ));
}

beforeEach(function (): void {
    Company::insert([
        ['id' => 1, 'name' => 'Acme'],
        ['id' => 2, 'name' => 'Initech'],
    ]);

    User::insert([
        ['id' => 1, 'company_id' => 1, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'status' => 'active', 'balance' => 10240.50, 'created_at' => '2026-01-14 09:12:00'],
        ['id' => 2, 'company_id' => 2, 'first_name' => 'Alan', 'last_name' => 'Turing', 'status' => 'suspended', 'balance' => -412.25, 'created_at' => '2026-02-02 16:45:00'],
        ['id' => 3, 'company_id' => 1, 'first_name' => 'Grace', 'last_name' => 'Hopper', 'status' => 'active', 'balance' => 500.00, 'created_at' => '2026-03-05 11:00:00'],
        // Two rows that only an escaped LIKE can tell apart.
        ['id' => 4, 'company_id' => null, 'first_name' => 'Edsger', 'last_name' => '100%', 'status' => null, 'balance' => 0.00, 'created_at' => '2026-04-01 08:00:00'],
        ['id' => 5, 'company_id' => null, 'first_name' => 'Barbara', 'last_name' => '1000', 'status' => null, 'balance' => 25.00, 'created_at' => '2026-05-09 08:00:00'],
    ]);

    Profile::insert([
        ['user_id' => 1, 'nickname' => 'countess'],
        ['user_id' => 2, 'nickname' => 'alan'],
    ]);

    Post::insert([
        ['user_id' => 1, 'title' => 'Notes'],
        ['user_id' => 1, 'title' => 'Analytical Engine'],
    ]);
});

it('sorts by several keys in the order the client sent them', function (): void {
    $names = lastNamesFor(auraRequest(['sortable' => [
        ['field' => 'status', 'direction' => 'asc'],
        ['field' => 'balance', 'direction' => 'desc'],
    ]]));

    // status ascending puts the two nulls first (SQLite), then active, then suspended;
    // within each group balance descends.
    expect($names)->toBe(['1000', '100%', 'Lovelace', 'Hopper', 'Turing']);
});

it('searches a column as a substring by default', function (): void {
    expect(lastNamesFor(auraRequest(['searchable' => [['field' => 'last_name', 'term' => 'ove']]])))
        ->toBe(['Lovelace']);
});

it('matches the whole value when exact is set', function (): void {
    expect(lastNamesFor(auraRequest(['searchable' => [['field' => 'last_name', 'term' => 'Lovelace', 'exact' => true]]])))
        ->toBe(['Lovelace'])
        ->and(lastNamesFor(auraRequest(['searchable' => [['field' => 'last_name', 'term' => 'Lov', 'exact' => true]]])))
        ->toBe([]);
});

it('treats a percent sign in the term as text, not as a wildcard', function (): void {
    // Unescaped, this would also return "1000" — a search box quietly turning
    // into a full scan is the failure mode being guarded here.
    expect(lastNamesFor(auraRequest(['searchable' => [['field' => 'last_name', 'term' => '100%']]])))
        ->toBe(['100%']);
});

it('treats an underscore in the term as text', function (): void {
    expect(lastNamesFor(auraRequest(['searchable' => [['field' => 'last_name', 'term' => '10_0']]])))
        ->toBe([]);
});

it('searches a numeric range, with either end open', function (): void {
    expect(lastNamesFor(auraRequest(['searchable' => [['field' => 'balance', 'min' => 0, 'max' => 1000]]])))
        ->toBe(['Hopper', '100%', '1000'])
        ->and(lastNamesFor(auraRequest(['searchable' => [['field' => 'balance', 'min' => 500, 'max' => null]]])))
        ->toBe(['Lovelace', 'Hopper'])
        ->and(lastNamesFor(auraRequest(['searchable' => [['field' => 'balance', 'min' => null, 'max' => 0]]])))
        ->toBe(['Turing', '100%']);
});

it('searches a date range', function (): void {
    expect(lastNamesFor(auraRequest(['searchable' => [[
        'field' => 'created_at',
        'min' => '2026-02-01 00:00:00',
        'max' => '2026-03-31 23:59:59',
    ]]])))->toBe(['Turing', 'Hopper']);
});

it('filters on the selected values', function (): void {
    expect(lastNamesFor(auraRequest(['filterable' => [['field' => 'status', 'values' => ['active']]]])))
        ->toBe(['Lovelace', 'Hopper']);
});

it('includes null rows when null is one of the selected values', function (): void {
    // `IN (…)` never matches NULL, so without the extra branch these rows vanish.
    expect(lastNamesFor(auraRequest(['filterable' => [['field' => 'status', 'values' => ['active', null]]]])))
        ->toBe(['Lovelace', 'Hopper', '100%', '1000']);
});

it('matches nothing when the filter selects no values', function (): void {
    expect(lastNamesFor(auraRequest(['filterable' => [['field' => 'status', 'values' => []]]])))
        ->toBe([]);
});

it('searches every field the table declared for global search', function (): void {
    expect(lastNamesFor(auraRequest(['globalSearch' => 'race'])))->toBe(['Hopper'])
        ->and(lastNamesFor(auraRequest(['globalSearch' => 'Initech'])))->toBe(['Turing']);
});

it('keeps the global search from widening the other constraints', function (): void {
    // The ORs live in their own group; leaking them would bring Turing back.
    expect(lastNamesFor(auraRequest([
        'filterable' => [['field' => 'status', 'values' => ['active']]],
        'globalSearch' => 'a',
    ])))->toBe(['Lovelace', 'Hopper']);
});

it('searches through a relation named with a dotted field', function (): void {
    expect(lastNamesFor(auraRequest(['searchable' => [['field' => 'company.name', 'term' => 'Acme']]])))
        ->toBe(['Lovelace', 'Hopper']);
});

it('filters through a relation named with a dotted field', function (): void {
    expect(lastNamesFor(auraRequest(['filterable' => [['field' => 'company.name', 'values' => ['Initech']]]])))
        ->toBe(['Turing']);
});

it('sorts through a belongsTo relation', function (): void {
    $names = lastNamesFor(auraRequest(['sortable' => [['field' => 'company.name', 'direction' => 'asc']]]));

    // Acme before Initech; the two company-less rows sort as null.
    expect(array_slice($names, -3))->toBe(['Lovelace', 'Hopper', 'Turing']);
});

it('sorts through a hasOne relation', function (): void {
    $names = lastNamesFor(auraRequest(['sortable' => [['field' => 'profile.nickname', 'direction' => 'desc']]]));

    expect(array_slice($names, 0, 2))->toBe(['Lovelace', 'Turing']);
});

it('does not multiply rows when sorting through a relation', function (): void {
    // A join would return one row per related record; Lovelace has two posts.
    expect(lastNamesFor(auraRequest(['sortable' => [['field' => 'company.name', 'direction' => 'asc']]])))
        ->toHaveCount(5);
});

it('refuses to sort through a to-many relation', function (): void {
    lastNamesFor(auraRequest(['sortable' => [['field' => 'posts.title', 'direction' => 'asc']]]));
})->throws(UnsupportedRelation::class, 'has no single value to order on');

it('refuses to sort through a nested relation path', function (): void {
    lastNamesFor(auraRequest(['sortable' => [['field' => 'company.owner.name', 'direction' => 'asc']]]));
})->throws(UnsupportedRelation::class, 'single relation level');

it('never lets the selected ids reach the query', function (): void {
    $withSelection = auraRequest(['selected' => [1, 2], 'filterable' => [['field' => 'status', 'values' => ['active']]]]);
    $without = auraRequest(['filterable' => [['field' => 'status', 'values' => ['active']]]]);

    expect(AuraQuery::apply(User::query(), $withSelection)->toSql())
        ->toBe(AuraQuery::apply(User::query(), $without)->toSql())
        ->and(lastNamesFor($withSelection))->toBe(['Lovelace', 'Hopper']);
});

it('builds items, meta and links that satisfy the contract', function (): void {
    $request = AuraRequest::fromArray(
        ['page' => 2, 'paginate' => 2, 'sortable' => [['field' => 'last_name', 'direction' => 'asc']]],
        queryPermissions(),
    );

    $payload = AuraPayload::fromPaginator(AuraQuery::paginate(User::query(), $request))->toArray();

    expect($payload['meta']['current_page'])->toBe(2)
        ->and($payload['meta']['last_page'])->toBe(3)
        ->and($payload['meta']['per_page'])->toBe(2)
        ->and($payload['meta']['total'])->toBe(5)
        ->and($payload['items'])->toHaveCount(2)
        // A page with gaps in its keys would serialise as a JSON object, and the
        // contract types `items` as an array.
        ->and(json_encode($payload['items']))->toStartWith('[');

    // The describing half arrives from the table definition in F3; until then a
    // hand-written minimal header makes this a complete, validatable response.
    assertMatchesAuraResponseSchema(json_decode((string) json_encode([
        'header' => ['rows' => [['cells' => [
            ['content' => 'Name', 'key' => 'last_name', 'field' => 'last_name'],
        ]]]],
    ] + $payload), false, 512, JSON_THROW_ON_ERROR));
});

it('refuses a paginator that cannot report last_page and total', function (): void {
    AuraPayload::fromPaginator(User::query()->simplePaginate(2));
})->throws(UnsupportedPaginator::class, 'LengthAwarePaginator');

it('paginates with the page and size the request asked for', function (): void {
    $paginator = AuraQuery::paginate(
        User::query(),
        AuraRequest::fromArray(['page' => 3, 'paginate' => 2], queryPermissions()),
    );

    expect($paginator)->toBeInstanceOf(LengthAwarePaginator::class)
        ->and($paginator->currentPage())->toBe(3)
        ->and($paginator->perPage())->toBe(2)
        ->and($paginator->total())->toBe(5);
});

it('refuses to sort through a method that is not a relation', function (): void {
    // `delete` exists on every model. Before the guard, resolving this field
    // *called* it on the way to discovering it is not a relation — and then
    // reported "only a single relation level is supported", which is an answer
    // to a question nobody asked.
    AuraQuery::apply(User::query(), AuraRequest::fromArray(
        ['page' => 1, 'paginate' => 10, 'sortable' => [['field' => 'delete.title', 'direction' => 'asc']]],
        new FieldPermissions(sortable: ['delete.title']),
    ))->toSql();
})->throws(UnsupportedRelation::class, 'is not a relation on this model');
