<?php

declare(strict_types=1);

use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Table\Action;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\ColumnGroup;
use TamasLabs\Aura\Tests\Fixtures\Status;
use TamasLabs\Aura\Tests\Fixtures\TypedCompany;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;

beforeEach(function (): void {
    $acme = TypedCompany::create(['name' => 'Acme', 'tier' => 'paid']);

    TypedUser::create([
        'company_id' => $acme->getKey(), 'first_name' => 'Ada', 'last_name' => 'Lovelace',
        'status' => Status::Active, 'balance' => 100, 'created_at' => '2024-01-01 10:00:00',
    ]);
});

/**
 * The columns of the last header row, which is the only row Aura reads them from.
 *
 * @param  array<string, mixed>  $definition
 * @return list<array<string, mixed>>
 */
function auraLastHeaderRow(array $definition): array
{
    $rows = auraDigArray($definition, 'header', 'rows');
    $last = $rows[array_key_last($rows)] ?? [];

    /** @var list<array<string, mixed>> $cells */
    $cells = is_array($last) ? ($last['cells'] ?? []) : [];

    return $cells;
}

// ---------------------------------------------------------------------------
// 5a.1 / 5a.2 — the header field goes out, and nothing else does
// ---------------------------------------------------------------------------

it('emits the actions as a multi-field header cell with no heading', function (): void {
    $definition = auraTable([
        Column::make('last_name'),
        Column::actions('id', Action::show(), Action::edit(), Action::destroy()),
    ])->definition();

    // The whole cell, not a few keys of it: an action column emits exactly
    // this and nothing more — no field, no flags, no configuration.
    expect(auraCell($definition, 'id'))->toBe([
        'content' => null,
        'key' => 'id',
        'fields' => ['show_icon', 'edit_icon', 'destroy_icon'],
    ]);
});

it('names every action field after its prefix', function (): void {
    $definition = auraTable([
        Column::actions('id', Action::create(), Action::show(), Action::edit(), Action::destroy()),
    ])->definition();

    expect(auraDig($definition, 'header', 'rows', 0, 'cells', 0, 'fields'))
        ->toBe(['create_icon', 'show_icon', 'edit_icon', 'destroy_icon']);
});

it('generates no cell configuration for an action column', function (): void {
    $definition = auraTable([
        Column::make('last_name'),
        Column::actions('id', Action::edit(), Action::destroy()),
    ])->definition();

    // Convention mode's whole point: the browser builds the route from its own
    // `urlParameter`, which the server never sees. Emitting a config here would
    // pre-empt that with a route built from nothing.
    expect($definition)->not->toHaveKey('body');
});

it('leaves the action fields out of the rows', function (): void {
    $response = auraTable([
        Column::make('last_name'),
        Column::actions('id', Action::edit()),
    ])->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    $items = auraDigArray($response, 'items');
    $first = $items[0] ?? [];

    expect($first)->toBeArray()
        ->and($first)->not->toHaveKey('edit_icon')
        ->and($first)->toHaveKey('id');
});

it('leaves the action column out of the whitelist', function (): void {
    $permissions = auraTable([
        Column::make('last_name')->sortable(),
        Column::actions('id', Action::edit()),
    ])->permissions();

    expect($permissions->sortable)->toBe(['last_name'])
        ->and($permissions->searchable)->toBe([])
        ->and($permissions->filterable)->toBe([])
        ->and($permissions->globalSearch)->toBe([]);
});

it('accepts a heading on the action column when one is wanted', function (): void {
    $definition = auraTable([
        Column::make('last_name'),
        Column::actions('id', Action::edit())->content('Actions')->align('end'),
    ])->definition();

    $cell = auraCell($definition, 'id');

    expect($cell['content'] ?? null)->toBe('Actions')
        ->and($cell['align'] ?? null)->toBe('end');
});

it('produces a response Aura would accept', function (): void {
    $response = auraTable([
        Column::selection()->key('select'),
        Column::make('last_name')->sortable(),
        Column::actions('id', Action::create(), Action::show(), Action::edit(), Action::destroy()),
    ])->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    assertMatchesAuraResponseSchema(auraObject($response));
});

// ---------------------------------------------------------------------------
// 5a.2 — INV9: the action column belongs in the *last* header row
// ---------------------------------------------------------------------------

it('puts the action column in the last header row of a grouped header', function (): void {
    $definition = auraTable([
        ColumnGroup::make('Name', [Column::make('first_name'), Column::make('last_name')]),
        Column::actions('id', Action::edit()),
    ])->definition();

    $keys = array_map(
        static fn (array $cell): mixed => $cell['key'] ?? null,
        auraLastHeaderRow($definition),
    );

    expect(auraDigArray($definition, 'header', 'rows'))->toHaveCount(2)
        ->and($keys)->toBe(['first_name', 'last_name', 'id']);
});

it('repeats the action key on the spacer without changing the route Aura builds', function (): void {
    $definition = auraTable([
        ColumnGroup::make('Name', [Column::make('first_name'), Column::make('last_name')]),
        Column::actions('id', Action::edit()),
    ])->definition();

    // Aura's icon preprocessor walks *every* header row, so the spacer above an
    // ungrouped action column is seen too. It is only harmless because it
    // carries the same key: the generated route is byte-identical, and the
    // first one wins. If the spacer ever stops carrying `key`, this breaks
    // silently — the route would be built against Aura's own `id` default.
    $spacer = auraDigArray($definition, 'header', 'rows', 0, 'cells', 1);

    expect($spacer['key'] ?? null)->toBe('id')
        ->and($spacer['fields'] ?? null)->toBe(['edit_icon'])
        ->and($spacer)->toHaveKey('content')
        ->and($spacer['content'])->toBeNull();
});

// ---------------------------------------------------------------------------
// 5a.3 — the key is the route placeholder, so it cannot be shared
// ---------------------------------------------------------------------------

it('refuses an action column whose key another column already holds', function (): void {
    auraTable([
        Column::make('id'),
        Column::actions('id', Action::edit()),
    ])->definition();
})->throws(InvalidDefinition::class, 'The action column keys on "id"');

it('refuses the collision in either order', function (): void {
    auraTable([
        Column::actions('id', Action::edit()),
        Column::make('id'),
    ])->definition();
})->throws(InvalidDefinition::class, 'route as the placeholder');

it('tells a colliding selection column what is safe to change', function (): void {
    // The default selection column keys on the model's primary key, which is
    // the same name the action column needs for its placeholder — so this is
    // the collision nearly every real table meets first.
    auraTable([
        Column::selection(),
        Column::actions('id', Action::edit()),
    ])->definition();
})->throws(InvalidDefinition::class, 'Re-key the selection column');

it('accepts the pair once the selection column is re-keyed', function (): void {
    $definition = auraTable([
        Column::selection()->key('select'),
        Column::actions('id', Action::edit()),
    ])->definition();

    $selection = auraCell($definition, 'select');

    // Re-keying moves the column's name in the payload and nothing else: Aura
    // still reads the row id from `field`.
    expect($selection['field'] ?? null)->toBe('id')
        ->and($selection['selectable'] ?? null)->toBeTrue()
        ->and(auraCell($definition, 'id')['fields'] ?? null)->toBe(['edit_icon']);
});

it('still reports a plain key collision the plain way', function (): void {
    auraTable([
        Column::make('last_name'),
        Column::make('first_name')->key('last_name'),
    ])->definition();
})->throws(InvalidDefinition::class, 'Two columns share the key');

it('accepts the action column beside a re-keyed identifier column', function (): void {
    $permissions = auraTable([
        Column::make('id')->key('identifier')->sortable(),
        Column::actions('id', Action::edit()),
    ])->permissions();

    // The re-keyed column still sorts by its field: a key names the column in
    // the payload, a field names the database column.
    expect($permissions->sortable)->toBe(['id']);
});

// ---------------------------------------------------------------------------
// 5a.4 — actions do not mix with data, and never appear twice
// ---------------------------------------------------------------------------

it('refuses the same action in two columns', function (): void {
    auraTable([
        Column::actions('id', Action::edit()),
        Column::actions('uuid', Action::edit()),
    ])->definition();
})->throws(InvalidDefinition::class, 'The action "edit_icon" appears in both column "id" and column "uuid"');

it('refuses the same action twice in one column', function (): void {
    auraTable([
        Column::actions('id', Action::edit(), Action::edit()),
    ])->definition();
})->throws(InvalidDefinition::class, 'appears in both column "id" and column "id"');

it('allows two action columns that offer different actions', function (): void {
    $definition = auraTable([
        Column::actions('id', Action::show()),
        Column::actions('uuid', Action::edit()),
    ])->definition();

    expect(auraCell($definition, 'id')['fields'] ?? null)->toBe(['show_icon'])
        ->and(auraCell($definition, 'uuid')['fields'] ?? null)->toBe(['edit_icon']);
});

it('refuses an action field on a data column', function (): void {
    auraTable([Column::make('edit_icon')])->definition();
})->throws(InvalidDefinition::class, 'which Aura reads as a built-in resource action');

it('refuses an action field smuggled into a combined column', function (): void {
    auraTable([
        Column::combined('actions', ['last_name', 'destroy_link']),
    ])->definition();
})->throws(InvalidDefinition::class, '"destroy_link"');

it('leaves a non-action icon field alone', function (): void {
    // `status_icon` is not a resource action: Aura renders a glyph with no
    // route for it, which is a rendering decision and none of this guard's
    // business.
    $definition = auraTable([Column::make('status_icon')])->definition();

    expect(auraCell($definition, 'status_icon'))->not->toBeNull();
});

it('refuses to sort, search or filter an action column', function (string $method, string $flag): void {
    $column = Column::actions('id', Action::edit());
    $column->{$method}();

    auraTable([$column])->definition();
})->throws(InvalidDefinition::class, 'its fields are routes rather than data')->with([
    ['sortable', 'sortable'],
    ['searchable', 'searchable'],
    ['filterable', 'filterable'],
    ['globalSearch', 'globalSearch'],
]);

it('names the operation it refused', function (): void {
    auraTable([Column::actions('id', Action::edit())->filterable()])->definition();
})->throws(InvalidDefinition::class, 'is marked filterable');

// ---------------------------------------------------------------------------
// Structural
// ---------------------------------------------------------------------------

it('refuses an action column with no actions', function (): void {
    Column::actions('id');
})->throws(InvalidDefinition::class, 'was given no actions');

it('refuses a multi-field column that names no field', function (): void {
    auraTable([Column::combined('full_name', [])])->definition();
})->throws(InvalidDefinition::class, 'names an empty fields list');
