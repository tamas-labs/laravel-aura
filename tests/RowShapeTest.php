<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use TamasLabs\Aura\Response\AuraPayload;
use TamasLabs\Aura\Response\RowFields;
use TamasLabs\Aura\Table\Action;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Tests\Fixtures\NarrowedTable;
use TamasLabs\Aura\Tests\Fixtures\Status;
use TamasLabs\Aura\Tests\Fixtures\TypedCompany;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;
use TamasLabs\Aura\Tests\Fixtures\UserTable;

beforeEach(function (): void {
    $acme = TypedCompany::create(['name' => 'Acme', 'tier' => 'paid']);

    TypedUser::create([
        'company_id' => $acme->getKey(), 'first_name' => 'Ada', 'last_name' => 'Lovelace',
        'email' => 'ada@example.test', 'status' => Status::Active, 'balance' => 500,
        'created_at' => '2024-01-01 10:00:00',
    ]);
});

/**
 * The first row of a table's answer.
 *
 * @param  array<string, mixed>  $response
 * @return array<string, mixed>
 */
function auraFirstRow(array $response): array
{
    $items = $response['items'] ?? [];
    $row = is_array($items) ? ($items[0] ?? []) : [];

    if (! is_array($row)) {
        return [];
    }

    /** @var array<string, mixed> $row */
    return $row;
}

it('sends every attribute the model is not hiding by default', function (): void {
    $row = auraFirstRow((new UserTable)->respond(auraHttpRequest(['page' => 1, 'paginate' => 10])));

    // The default is the whole model, and it is deliberate rather than
    // accidental: the shape of a row is public surface, so narrowing it for
    // everyone would be a major version. `email` is here because nothing in the
    // definition mentions it — which is exactly what $onlyDeclaredFields is for.
    expect($row)->toHaveKeys(['id', 'company_id', 'first_name', 'last_name', 'email', 'status', 'balance', 'created_at'])
        ->and($row['email'])->toBe('ada@example.test')
        ->and($row['company'])->toHaveKeys(['id', 'name', 'tier']);
});

it('narrows the rows to the fields the definition names', function (): void {
    $row = auraFirstRow((new NarrowedTable)->respond(auraHttpRequest(['page' => 1, 'paginate' => 10])));

    expect(array_keys($row))->toBe(['id', 'last_name', 'status', 'balance', 'company'])
        ->and($row)->not->toHaveKey('email')
        ->and($row)->not->toHaveKey('first_name')
        ->and($row)->not->toHaveKey('company_id')
        ->and($row)->not->toHaveKey('created_at');
});

it('narrows an eager-loaded relation to the field a column reads', function (): void {
    $row = auraFirstRow((new NarrowedTable)->respond(auraHttpRequest(['page' => 1, 'paginate' => 10])));

    // `->with('company')` sends the whole company row per user otherwise; the
    // definition names `company.name` and nothing else.
    expect($row['company'])->toBe(['name' => 'Acme']);
});

it('keeps a field only a condition names, and still hands it over as a number', function (): void {
    $row = auraFirstRow((new NarrowedTable)->respond(auraHttpRequest(['page' => 1, 'paginate' => 10])));

    // No column reads `balance`; the badge's condition compares it. Dropping it
    // would leave the condition permanently false, silently — and it still has
    // to arrive as a number, so the narrowing must not run after the coercion.
    expect($row['balance'])->toBe(500);
});

it('narrows what transform() returned, not the model', function (): void {
    $table = new NarrowedTable(static fn (Model $model): array => [
        'id' => $model->getKey(),
        'last_name' => 'REPLACED',
        'internal_score' => 42,
    ]);

    $row = auraFirstRow($table->respond(auraHttpRequest(['page' => 1, 'paginate' => 10])));

    expect($row)->toBe(['id' => 1, 'last_name' => 'REPLACED']);
});

it('takes the rows from transform() as they are when the switch is off', function (): void {
    $table = new NarrowedTable(
        static fn (Model $model): array => ['id' => $model->getKey(), 'shouted' => 'ADA'],
        narrow: false,
    );

    $row = auraFirstRow($table->respond(auraHttpRequest(['page' => 1, 'paginate' => 10])));

    expect($row)->toBe(['id' => 1, 'shouted' => 'ADA']);
});

it('keeps the permission flag a narrowed row carries', function (): void {
    $table = new NarrowedTable(
        definition: [
            Column::make('last_name'),
            Column::actions('id', Action::edit()->allowedWhen(static fn (TypedUser $user): bool => false)),
        ],
        resource: 'admin/users',
    );

    $row = auraFirstRow($table->respond(auraHttpRequest(['page' => 1, 'paginate' => 10])));

    // The flag is written after the narrowing, and it has to be: an absent flag
    // hides the cell exactly like a denial does (INV4), so a narrowing that ate
    // it would be indistinguishable from a table nobody may act on.
    expect($row['_allowed_edit_icon'])->toBeFalse();
});

it('leaves a page of plain rows alone when a transform is given', function (): void {
    // Only a model has anything to transform: a paginator of plain arrays is
    // somebody else's shape already, and this class does not own it.
    $payload = AuraPayload::fromPaginator(
        new LengthAwarePaginator([['id' => 1, 'name' => 'raw']], 1, 10),
        static fn (mixed $item): array => ['unreachable' => true],
    );

    expect($payload->items)->toBe([['id' => 1, 'name' => 'raw']]);
});

it('hands every model of the page to the transform', function (): void {
    $payload = AuraPayload::fromPaginator(
        TypedUser::query()->paginate(10),
        static fn (Model $model): array => ['id' => $model->getKey()],
    );

    expect($payload->items)->toBe([['id' => 1]]);
});

it('keeps a whole relation when the definition names it as well as a field in it', function (): void {
    $fields = RowFields::fromDefinition(['header' => ['rows' => [['cells' => [
        ['field' => 'company.name', 'key' => 'company.name'],
        ['field' => 'company', 'key' => 'company'],
    ]]]]]);

    expect($fields->paths())->toBe(['company'])
        ->and($fields->narrow(['company' => ['id' => 1, 'name' => 'Acme'], 'email' => 'x']))
        ->toBe(['company' => ['id' => 1, 'name' => 'Acme']]);
});

it('keeps the fields a route fills in per row', function (): void {
    $fields = RowFields::fromDefinition(['body' => ['columnConfigs' => [
        'edit_icon' => ['type' => 'icon', 'route' => 'admin/users/{uuid}/edit', 'key' => 'uuid'],
    ]]]);

    expect($fields->paths())->toBe(['edit_icon', 'uuid']);
});

it('narrows every row of a nested list', function (): void {
    $fields = RowFields::fromDefinition(['header' => ['rows' => [['cells' => [
        ['field' => 'posts.title', 'key' => 'posts.title'],
    ]]]]]);

    expect($fields->paths())->toBe(['posts.title'])
        ->and($fields->narrow(['posts' => [['id' => 1, 'title' => 'One'], ['id' => 2, 'title' => 'Two'], 'odd']]))
        ->toBe(['posts' => [['title' => 'One'], ['title' => 'Two'], 'odd']]);
});

it('keeps a whole relation when a field name ends in a dot', function (): void {
    // Any string can reach here through a hand-written merge() payload. A
    // malformed name narrows to nothing if the empty segment is taken
    // seriously, so it is dropped instead — and a name that is nothing but
    // dots names no field at all.
    $fields = RowFields::fromDefinition(['header' => ['rows' => [['cells' => [
        ['field' => 'company.'],
        ['field' => '..'],
    ]]]]]);

    expect($fields->paths())->toBe(['company'])
        ->and($fields->narrow(['company' => ['id' => 1, 'name' => 'Acme']]))
        ->toBe(['company' => ['id' => 1, 'name' => 'Acme']]);
});

it('leaves a value that is not a row alone', function (): void {
    $fields = RowFields::fromDefinition(['header' => ['rows' => [['cells' => [
        ['field' => 'company.name', 'key' => 'company.name'],
    ]]]]]);

    // A null relation is still a null relation, and a page of anything but rows
    // has no field to look up.
    expect($fields->narrow(['company' => null]))->toBe(['company' => null])
        ->and($fields->narrowAll([['company' => ['name' => 'Acme']], 'not a row']))
        ->toBe([['company' => ['name' => 'Acme']], 'not a row']);
});
