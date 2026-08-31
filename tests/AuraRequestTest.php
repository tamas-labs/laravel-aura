<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use TamasLabs\Aura\Query\FieldPermissions;
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Request\RequestLimits;
use TamasLabs\AuraSchema\AuraSchema;

/**
 * Everything the shipped request example names, and nothing more.
 */
function examplePermissions(): FieldPermissions
{
    return new FieldPermissions(
        sortable: ['last_name', 'created_at'],
        searchable: ['first_name', 'balance'],
        filterable: ['status'],
        globalSearch: ['first_name', 'last_name'],
    );
}

it('parses the request example shipped with the contract', function (): void {
    /** @var array<string, mixed> $payload */
    $payload = json_decode(
        (string) file_get_contents(AuraSchema::examplePath('request')),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $request = AuraRequest::fromHttp(auraHttpRequest($payload), examplePermissions());

    expect($request->page)->toBe(2)
        ->and($request->paginate)->toBe(25)
        ->and($request->sortable)->toHaveCount(2)
        ->and($request->sortable[0]->field)->toBe('last_name')
        ->and($request->sortable[0]->direction)->toBe('asc')
        ->and($request->sortable[1]->direction)->toBe('desc')
        ->and($request->searchable)->toHaveCount(2)
        ->and($request->searchable[0]->term)->toBe('ada')
        ->and($request->searchable[0]->exact)->toBeFalse()
        ->and($request->searchable[1]->isRange())->toBeTrue()
        ->and($request->searchable[1]->min)->toBe(0)
        ->and($request->searchable[1]->max)->toBeNull()
        ->and($request->filterable)->toHaveCount(1)
        ->and($request->filterable[0]->values)->toBe(['active', 'suspended'])
        ->and($request->globalSearch)->toBe('lovelace')
        ->and($request->selected)->toBe([1, 2, 7]);
});

it('reads the payload from query parameters on GET', function (): void {
    // GET carries everything as strings; the DTO still has to come out typed.
    $request = AuraRequest::fromHttp(
        auraHttpRequest([
            'page' => '3',
            'paginate' => '10',
            'sortable' => [['field' => 'last_name', 'direction' => 'desc']],
            'searchable' => [['field' => 'first_name', 'term' => 'ada', 'exact' => 'true']],
        ], 'GET'),
        examplePermissions(),
    );

    expect($request->page)->toBe(3)
        ->and($request->paginate)->toBe(10)
        ->and($request->sortable[0]->direction)->toBe('desc')
        ->and($request->searchable[0]->exact)->toBeTrue();
});

it('rejects a request without page or paginate', function (): void {
    AuraRequest::fromArray(['page' => 1], FieldPermissions::none());
})->throws(ValidationException::class);

it('rejects a page below one', function (): void {
    AuraRequest::fromArray(['page' => 0, 'paginate' => 10], FieldPermissions::none());
})->throws(ValidationException::class);

it('rejects an unknown top-level property', function (): void {
    // The request schema sets additionalProperties: false.
    AuraRequest::fromArray(
        ['page' => 1, 'paginate' => 10, 'orderBy' => 'password'],
        FieldPermissions::none(),
    );
})->throws(ValidationException::class);

it('rejects an unknown property inside sortable', function (): void {
    AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [['field' => 'last_name', 'direction' => 'asc', 'raw' => '1']],
    ], new FieldPermissions(sortable: ['last_name']));
})->throws(ValidationException::class);

it('rejects an unknown sort direction', function (): void {
    AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [['field' => 'last_name', 'direction' => 'asc; drop table users']],
    ], new FieldPermissions(sortable: ['last_name']));
})->throws(ValidationException::class);

it('clamps paginate to the configured ceiling', function (): void {
    config()->set('aura.pagination.max', 100);

    $request = AuraRequest::fromArray(['page' => 1, 'paginate' => 1_000_000], FieldPermissions::none());

    expect($request->paginate)->toBe(100);
});

it('leaves a paginate below the ceiling alone', function (): void {
    config()->set('aura.pagination.max', 100);

    expect(AuraRequest::fromArray(['page' => 1, 'paginate' => 25], FieldPermissions::none())->paginate)
        ->toBe(25);
});

it('refuses a field the table never offered', function (string $key, string $field): void {
    AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        $key => [match ($key) {
            'sortable' => ['field' => $field, 'direction' => 'asc'],
            'filterable' => ['field' => $field, 'values' => ['x']],
            default => ['field' => $field, 'term' => 'x'],
        }],
    ], new FieldPermissions(
        sortable: ['last_name'],
        searchable: ['last_name'],
        filterable: ['status'],
    ));
})->with([
    ['sortable', 'password_hash'],
    ['searchable', 'password_hash'],
    ['filterable', 'password_hash'],
])->throws(ValidationException::class);

it('allows nothing when no permissions are given', function (string $key, array $entry): void {
    // An empty whitelist means nothing is allowed — never everything.
    AuraRequest::fromArray(
        ['page' => 1, 'paginate' => 10, $key => [$entry]],
        FieldPermissions::none(),
    );
})->with([
    ['sortable', ['field' => 'id', 'direction' => 'asc']],
    ['searchable', ['field' => 'id', 'term' => 'x']],
    ['filterable', ['field' => 'id', 'values' => [1]]],
])->throws(ValidationException::class);

it('does not treat a prefix of an allowed field as allowed', function (): void {
    AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [['field' => 'last', 'direction' => 'asc']],
    ], new FieldPermissions(sortable: ['last_name']));
})->throws(ValidationException::class);

it('keeps selected ids without letting them near the whitelist', function (): void {
    // `selected` names row ids, not columns, so it is not whitelist-checked —
    // and it must never reach the query. F2 hands it to the caller untouched.
    $request = AuraRequest::fromArray(
        ['page' => 1, 'paginate' => 10, 'selected' => [4, '7']],
        FieldPermissions::none(),
    );

    expect($request->selected)->toBe([4, '7']);
});

it('refuses more entries than the table has fields to offer', function (): void {
    // The whitelist is the ceiling, and it is exact: Aura keeps one entry per
    // field, so a table offering two sortable columns can never have produced a
    // third sort. Before this, 5 000 entries built 125 kB of SQL.
    AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        'sortable' => array_fill(0, 5_000, ['field' => 'last_name', 'direction' => 'asc']),
    ], new FieldPermissions(sortable: ['last_name', 'created_at']));
})->throws(ValidationException::class, 'at most one entry per field');

it('bounds each list by its own whitelist, not by a number', function (): void {
    // Three sortable columns really do allow three sorts — the ceiling follows
    // the columns rather than a config value that could go stale beside them.
    $fields = new FieldPermissions(sortable: ['a', 'b', 'c']);

    $request = AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [
            ['field' => 'a', 'direction' => 'asc'],
            ['field' => 'b', 'direction' => 'desc'],
            ['field' => 'c', 'direction' => 'asc'],
        ],
    ], $fields);

    expect($request->sortable)->toHaveCount(3);

    AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [
            ['field' => 'a', 'direction' => 'asc'],
            ['field' => 'b', 'direction' => 'desc'],
            ['field' => 'c', 'direction' => 'asc'],
            ['field' => 'a', 'direction' => 'desc'],
        ],
    ], $fields);
})->throws(ValidationException::class);

it('refuses the same field twice in one list', function (string $key, array $entry): void {
    // Aura updates the existing entry instead of pushing a second one, so a
    // repeat is never something the table produced — and two sorts on one field
    // would mean ORDER BY x ASC, x DESC.
    AuraRequest::fromArray(
        ['page' => 1, 'paginate' => 10, $key => [$entry, $entry]],
        new FieldPermissions(
            sortable: ['last_name', 'created_at'],
            searchable: ['last_name', 'created_at'],
            filterable: ['status', 'tier'],
        ),
    );
})->with([
    ['sortable', ['field' => 'last_name', 'direction' => 'asc']],
    ['searchable', ['field' => 'last_name', 'term' => 'x']],
    ['filterable', ['field' => 'status', 'values' => ['active']]],
])->throws(ValidationException::class, 'more than once');

it('refuses a search term longer than the ceiling', function (): void {
    AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        'searchable' => [['field' => 'last_name', 'term' => str_repeat('a', 256)]],
    ], new FieldPermissions(searchable: ['last_name']));
})->throws(ValidationException::class);

it('refuses a global search longer than the ceiling', function (): void {
    // Measured before the ceiling existed: 200 000 characters were accepted, and
    // became one LIKE '%…%' per global-search field.
    AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        'globalSearch' => str_repeat('a', 200_000),
    ], new FieldPermissions(globalSearch: ['last_name']));
})->throws(ValidationException::class);

it('refuses a selection larger than the ceiling', function (): void {
    // The one list nothing on the server can derive a bound for: the selection
    // survives paging, so it grows with what the user ticks.
    AuraRequest::fromArray(
        ['page' => 1, 'paginate' => 10, 'selected' => range(1, 50_000)],
        FieldPermissions::none(),
    );
})->throws(ValidationException::class, 'aura.limits.selected');

it('refuses a filter carrying more values than the ceiling', function (): void {
    AuraRequest::fromArray([
        'page' => 1,
        'paginate' => 10,
        'filterable' => [['field' => 'status', 'values' => range(1, 201)]],
    ], new FieldPermissions(filterable: ['status']));
})->throws(ValidationException::class);

it('takes an explicit limit over the configured one', function (): void {
    config()->set('aura.limits.term', 255);

    AuraRequest::fromArray(
        ['page' => 1, 'paginate' => 10, 'globalSearch' => 'abcdefghijk'],
        new FieldPermissions(globalSearch: ['last_name']),
        new RequestLimits(term: 10),
    );
})->throws(ValidationException::class);

it('leaves the limits it was not given at their configured values', function (): void {
    // A partial override must not quietly discard the host app's config for
    // everything else.
    config()->set('aura.pagination.max', 40);

    $limits = new RequestLimits(term: 10);

    expect($limits->term)->toBe(10)
        ->and($limits->paginate)->toBe(40)
        ->and($limits->selected)->toBe(RequestLimits::SELECTED);
});

it('falls back to the packaged default rather than to no limit', function (): void {
    // A limit a broken config can switch off is not a limit.
    config()->set('aura.limits.selected', 0);
    config()->set('aura.limits.term', 'not a number');
    config()->set('aura.pagination.max', null);

    $limits = RequestLimits::fromConfig();

    expect($limits->selected)->toBe(RequestLimits::SELECTED)
        ->and($limits->term)->toBe(RequestLimits::TERM)
        ->and($limits->paginate)->toBe(RequestLimits::PAGINATE);
});
