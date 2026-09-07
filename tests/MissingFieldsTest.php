<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use TamasLabs\Aura\Response\MissingFields;
use TamasLabs\Aura\Table\Action;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Tests\Fixtures\LazyRelationTable;
use TamasLabs\Aura\Tests\Fixtures\Status;
use TamasLabs\Aura\Tests\Fixtures\TypedCompany;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;

beforeEach(function (): void {
    $acme = TypedCompany::create(['name' => 'Acme', 'tier' => 'paid']);

    TypedUser::create([
        'company_id' => $acme->getKey(), 'first_name' => 'Ada', 'last_name' => 'Lovelace',
        'status' => Status::Active, 'balance' => 500,
    ]);

    config(['app.debug' => true]);
});

/**
 * One page from a table, with the request the tests all send.
 *
 * @return array<string, mixed>
 */
function auraLazyPage(LazyRelationTable $table): array
{
    return $table->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));
}

it('warns when a dotted column reads a relation nothing loaded', function (): void {
    Log::shouldReceive('warning')
        ->once()
        ->withArgs(function (string $message, array $context): bool {
            return str_contains($message, 'company.name')
                && str_contains($message, "->with('company')")
                && $context['field'] === 'company.name'
                && $context['missing'] === 'company';
        });

    $response = auraLazyPage(new LazyRelationTable(eager: false));

    // The point of the warning: the column is empty and nothing else says so.
    expect(auraDigArray($response, 'items', 0))->not->toHaveKey('company');
});

it('says nothing when the relation is eager loaded', function (): void {
    Log::shouldReceive('warning')->never();

    $response = auraLazyPage(new LazyRelationTable(eager: true));

    expect(auraDig($response, 'items', 0, 'company', 'name'))->toBe('Acme');
});

it('says nothing about a relation that is loaded and empty for the row', function (): void {
    // A loaded relation with nothing on the other end is data, not a mistake:
    // the key is there, carrying null.
    TypedUser::query()->update(['company_id' => null]);

    Log::shouldReceive('warning')->never();

    $response = auraLazyPage(new LazyRelationTable(eager: true));

    expect(auraDigArray($response, 'items', 0))->toHaveKey('company')
        ->and(auraDig($response, 'items', 0, 'company'))->toBeNull();
});

it('says nothing outside debug mode', function (): void {
    config(['app.debug' => false]);

    Log::shouldReceive('warning')->never();

    auraLazyPage(new LazyRelationTable(eager: false));
});

it('says nothing about a dotted path the rows carry for another reason', function (): void {
    // A hand-built row shape, or a JSON cast read as `meta.theme`: the root is
    // in the row, so there is nothing to report and no relation to reason about.
    Log::shouldReceive('warning')->never();

    $response = auraLazyPage(new LazyRelationTable(
        eager: false,
        definition: [Column::make('last_name'), Column::make('meta.theme')],
        rows: static fn (Model $model): array => ['last_name' => 'Lovelace', 'meta' => ['theme' => 'dark']],
    ));

    expect(auraDig($response, 'items', 0, 'meta', 'theme'))->toBe('dark');
});

it('says nothing about a flat field the rows were never meant to carry', function (): void {
    // `edit_icon` is a header field with no value behind it, and an action
    // column's key is an identifier this check cannot verify — both are absent
    // from every row by design, so only dotted paths are examined.
    Log::shouldReceive('warning')->never();

    auraLazyPage(new LazyRelationTable(
        eager: false,
        definition: [Column::make('last_name'), Column::actions('id', Action::edit())],
    ));
});

it('says nothing about a path that walks into a to-many relation', function (): void {
    // `posts` is a list: there is no name to index it by, and Aura would not
    // resolve one either, so the walk stops rather than reporting a gap.
    Log::shouldReceive('warning')->never();

    auraLazyPage(new LazyRelationTable(
        eager: false,
        definition: [Column::make('last_name'), Column::make('posts.author.name')],
        rows: static fn (Model $model): array => [
            'last_name' => 'Lovelace',
            'posts' => [['title' => 'On the Analytical Engine']],
        ],
    ));
});

it('says nothing about a row that is not keyed by field at all', function (): void {
    // A paginator of plain lists — the same case `RowFields::narrowAll()` hands
    // back untouched. There is no field to look up in it.
    Log::shouldReceive('warning')->never();

    MissingFields::report(
        ['header' => ['rows' => [['cells' => [['content' => 'Company', 'field' => 'company.name']]]]]],
        [['Ada', 'Lovelace']],
        'Tests\Fixtures\LazyRelationTable',
    );
});
