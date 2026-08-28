<?php

declare(strict_types=1);

use Illuminate\Validation\ValidationException;
use TamasLabs\Aura\Query\FieldPermissions;
use TamasLabs\Aura\Request\AuraRequest;
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
