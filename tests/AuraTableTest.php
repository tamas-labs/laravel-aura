<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Query\FieldPermissions;
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\ColumnGroup;
use TamasLabs\Aura\Table\Footer;
use TamasLabs\Aura\Table\TableSettings;
use TamasLabs\Aura\Tests\Fixtures\CachedTable;
use TamasLabs\Aura\Tests\Fixtures\Status;
use TamasLabs\Aura\Tests\Fixtures\TypedCompany;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;
use TamasLabs\Aura\Tests\Fixtures\UserTable;

beforeEach(function (): void {
    $acme = TypedCompany::create(['name' => 'Acme', 'tier' => 'paid']);
    $globex = TypedCompany::create(['name' => 'Globex', 'tier' => 'free_trial']);

    TypedUser::create([
        'company_id' => $acme->getKey(), 'first_name' => 'Ada', 'last_name' => 'Lovelace',
        'status' => Status::Active, 'balance' => 100, 'created_at' => '2024-01-01 10:00:00',
    ]);
    TypedUser::create([
        'company_id' => $globex->getKey(), 'first_name' => 'Grace', 'last_name' => 'Hopper',
        'status' => Status::Suspended, 'balance' => 500, 'created_at' => '2024-06-01 10:00:00',
    ]);
    TypedUser::create([
        'company_id' => $acme->getKey(), 'first_name' => 'Alan', 'last_name' => 'Turing',
        'status' => Status::Active, 'balance' => 42, 'created_at' => '2024-03-01 10:00:00',
    ]);
});

/**
 * The field Aura resolves a cell to, exactly as `resolve-cell-field.ts` does.
 *
 * @param  array<string, mixed>  $cell
 */
function auraResolvedField(array $cell): string
{
    foreach (['reference', 'field', 'key'] as $candidate) {
        $value = $cell[$candidate] ?? null;

        if (is_string($value) && $value !== '') {
            return $value;
        }
    }

    return '';
}

/**
 * Every data cell of a built definition.
 *
 * @param  array<string, mixed>  $definition
 * @return list<array<string, mixed>>
 */
function auraCells(array $definition): array
{
    $cells = [];

    foreach (auraDigArray($definition, 'header', 'rows') as $index => $row) {
        foreach (auraDigArray($row, 'cells') as $cell) {
            if (is_array($cell)) {
                /** @var array<string, mixed> $cell */
                $cells[] = $cell;
            }
        }

        unset($index);
    }

    return $cells;
}

it('serves a whole request from a table class', function (): void {
    $response = (new UserTable)->respond(auraHttpRequest(['page' => 1, 'paginate' => 2]));

    expect(auraDigArray($response, 'items'))->toHaveCount(2)
        ->and(auraDig($response, 'meta', 'total'))->toBe(3);

    assertMatchesAuraResponseSchema(auraObject($response));
});

it('sorts, searches and filters through the definition the browser was given', function (): void {
    $response = (new UserTable)->respond(auraHttpRequest([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [['field' => 'last_name', 'direction' => 'desc']],
        'filterable' => [['field' => 'status', 'values' => ['active']]],
    ]));

    expect(array_column(auraDigArray($response, 'items'), 'last_name'))->toBe(['Turing', 'Lovelace']);

    assertMatchesAuraResponseSchema(auraObject($response));
});

it('allows exactly the operations its header advertises', function (): void {
    $definition = (new UserTable)->definition();
    $permissions = (new UserTable)->permissions();

    $operations = ['sortable', 'searchable', 'filterable'];
    $checked = 0;

    foreach (auraCells($definition) as $cell) {
        $field = auraResolvedField($cell);

        foreach ($operations as $operation) {
            $advertised = (bool) ($cell[$operation] ?? false);
            $allowed = match ($operation) {
                'sortable' => $permissions->allowsSort($field),
                'searchable' => $permissions->allowsSearch($field),
                default => $permissions->allowsFilter($field),
            };

            expect($allowed)->toBe(
                $advertised,
                sprintf('column "%s" advertises %s=%s but the whitelist says %s',
                    $field, $operation, var_export($advertised, true), var_export($allowed, true)),
            );

            $checked++;
        }
    }

    // Guard the guard: an empty header would pass the loop above vacuously.
    expect($checked)->toBe(18);
});

it('whitelists the reference, not the column it is rendered from', function (): void {
    $permissions = (new UserTable)->permissions();

    expect($permissions->allowsSort('last_name'))->toBeTrue()
        ->and($permissions->allowsSort('full_name'))->toBeFalse();
});

it('whitelists the reference over the column own field', function (): void {
    // The case Aura's own `reference || field || key` order decides: a column
    // that renders the company name but sorts on the foreign key beside it.
    $table = auraTable([Column::make('company.name')->sortable()->reference('company_id')]);

    expect($table->permissions()->sortable)->toBe(['company_id'])
        ->and($table->permissions()->allowsSort('company.name'))->toBeFalse();

    $response = $table->respond(auraHttpRequest([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [['field' => 'company_id', 'direction' => 'desc']],
    ]));

    expect(array_column(auraDigArray($response, 'items'), 'last_name'))->toBe(['Hopper', 'Lovelace', 'Turing']);
});

it('publishes the global search under the browser\'s name and searches the database\'s', function (): void {
    // A rendered column: the row carries `company_name`, the database knows
    // `company.name`. The two lists have different consumers and so different
    // names — Aura matches `searchableItems` against a header cell's `field`
    // and resolves it against the item, while the whitelist is what ends up in
    // a WHERE. Sending the reference to the browser fails the header validation
    // outright; sending the field to the query layer is `Unknown column`.
    $table = auraTable([
        Column::make('company_name', 'Company')->reference('company.name')->globalSearch(),
    ]);

    expect(auraDig($table->definition(), 'header', 'settings', 'searchableItems'))->toBe(['company_name'])
        ->and($table->permissions()->globalSearch)->toBe(['company.name']);
});

it('runs a global search over a column the rows name differently', function (): void {
    // The end-to-end half of the test above: before the whitelist named the
    // reference, this query was `where typed_users.company_name like …`.
    $response = auraTable([
        Column::make('company_name', 'Company')->reference('company.name')->globalSearch(),
    ])->respond(auraHttpRequest(['page' => 1, 'paginate' => 10, 'globalSearch' => 'Globex']));

    expect(array_column(auraDigArray($response, 'items'), 'last_name'))->toBe(['Hopper']);
});

it('publishes only searchable items Aura can match to a header cell', function (): void {
    // validateHeaderSettings refuses an item that is not the `field` of some
    // header cell, and it does not fall back — one unmatched entry aborts the
    // whole header and replaces the table with the error UI.
    $definition = (new UserTable)->definition();

    $fields = array_values(array_filter(array_map(
        static fn (array $cell): mixed => $cell['field'] ?? null,
        auraCells($definition),
    ), is_string(...)));

    expect(auraDig($definition, 'header', 'settings', 'searchableItems'))->each->toBeIn($fields);
});

it('sorts a multi-field column by its reference', function (): void {
    $response = (new UserTable)->respond(auraHttpRequest([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [['field' => 'last_name', 'direction' => 'asc']],
    ]));

    expect(array_column(auraDigArray($response, 'items'), 'last_name'))->toBe(['Hopper', 'Lovelace', 'Turing']);
});

it('publishes the global search fields and accepts a search over them', function (): void {
    $definition = (new UserTable)->definition();

    expect(auraDig($definition, 'header', 'settings', 'searchableItems'))->toBe(['company.name'])
        ->and((new UserTable)->permissions()->globalSearch)->toBe(['company.name']);

    $response = (new UserTable)->respond(auraHttpRequest([
        'page' => 1, 'paginate' => 10, 'globalSearch' => 'Globex',
    ]));

    expect(array_column(auraDigArray($response, 'items'), 'last_name'))->toBe(['Hopper']);
});

it('refuses a field no column offered', function (): void {
    (new UserTable)->respond(auraHttpRequest([
        'page' => 1,
        'paginate' => 10,
        'sortable' => [['field' => 'first_name', 'direction' => 'asc']],
    ]));
})->throws(ValidationException::class);

it('splits the settings across the blocks the contract puts them in', function (): void {
    $definition = (new UserTable)->definition();

    expect(auraDig($definition, 'header', 'settings', 'sticky'))->toBeTrue()
        ->and(auraDig($definition, 'body', 'settings'))->toBe(['striped' => true, 'hoverable' => true]);
});

it('collects data-cell classes into columnStyles', function (): void {
    $definition = auraTable([
        Column::make('last_name')->cellClass('text-nowrap'),
        Column::make('balance'),
    ])->definition();

    expect(auraDig($definition, 'body', 'columnStyles'))->toBe(['last_name' => 'text-nowrap']);
});

it('omits the body block when nothing needs it', function (): void {
    expect(auraTable([Column::make('last_name')])->definition())->not->toHaveKey('body');
});

it('builds a two-row header from column groups', function (): void {
    $definition = auraTable([
        Column::selection(),
        ColumnGroup::make('User', [Column::make('first_name'), Column::make('last_name')]),
        ColumnGroup::make('Account', [Column::make('status'), Column::make('balance')]),
    ])->definition();

    expect(auraDigArray($definition, 'header', 'rows'))->toHaveCount(2)
        ->and(auraDig($definition, 'header', 'rows', 0, 'cells', 0))
        ->toBe(['content' => null, 'field' => 'id', 'key' => 'id'])
        ->and(auraDig($definition, 'header', 'rows', 0, 'cells', 1))
        ->toBe(['content' => 'User', 'colspan' => 2, 'align' => 'center'])
        ->and(array_column(auraDigArray($definition, 'header', 'rows', 1, 'cells'), 'key'))
        ->toBe(['id', 'first_name', 'last_name', 'status', 'balance']);

    assertMatchesAuraResponseSchema(auraObject($definition));
});

it('puts every data column in the last header row, where Aura looks for them', function (): void {
    // TableBody.tsx derives the columns from header.rows[last] alone: a column
    // that only appears in an earlier row renders a heading and no data.
    $table = auraTable([
        Column::selection(),
        Column::make('last_name'),
        ColumnGroup::make('Account', [Column::make('status'), Column::make('balance')]),
    ]);

    $rows = auraDigArray($table->definition(), 'header', 'rows');
    $last = auraDigArray($rows[count($rows) - 1], 'cells');

    expect(array_column($last, 'key'))->toBe(['id', 'last_name', 'status', 'balance']);

    // And the first row stays exactly as wide, or the header goes ragged.
    $width = array_sum(array_map(
        static fn (mixed $cell): int => is_array($cell) && is_int($cell['colspan'] ?? null) ? $cell['colspan'] : 1,
        auraDigArray($rows[0], 'cells'),
    ));

    expect($width)->toBe(count($last));
});

it('keeps the selection column in the last row of a grouped header', function (): void {
    // Aura renders the row checkboxes from the `selectable` cell among *those*
    // columns, so a selection column stranded in the first row disables
    // selection without saying so.
    $definition = auraTable([
        Column::selection(),
        ColumnGroup::make('User', [Column::make('first_name'), Column::make('last_name')]),
    ])->definition();

    $rows = auraDigArray($definition, 'header', 'rows');
    $last = auraDigArray($rows[count($rows) - 1], 'cells');

    expect(array_filter($last, static fn (mixed $cell): bool => is_array($cell) && ($cell['selectable'] ?? false) === true))
        ->toHaveCount(1);

    assertMatchesAuraResponseSchema(auraObject($definition));
});

it('refuses a group that spans a single column', function (): void {
    ColumnGroup::make('User', [Column::make('first_name')]);
})->throws(InvalidDefinition::class, 'at least two');

it('builds a footer from the same cells as the header', function (): void {
    $definition = auraTable(
        columns: [Column::make('last_name'), Column::make('balance')],
        footer: Footer::make(Column::heading('Total', colspan: 2)),
        settings: TableSettings::make()->stickyFooter(),
    )->definition();

    expect(auraDig($definition, 'footer'))->toBe([
        'rows' => [['cells' => [['content' => 'Total', 'colspan' => 2]]]],
        'settings' => ['sticky' => true],
    ]);

    assertMatchesAuraResponseSchema(auraObject($definition));
});

it('refuses two columns sharing a key', function (): void {
    auraTable([
        Column::make('last_name'),
        Column::make('first_name')->key('last_name'),
    ])->definition();
})->throws(InvalidDefinition::class, 'share the key');

it('refuses a multi-field column with no field to send', function (): void {
    auraTable([Column::combined('full_name', ['first_name', 'last_name'])->sortable()])->definition();
})->throws(InvalidDefinition::class, 'reference');

it('refuses a multi-field column in the global search', function (): void {
    auraTable([Column::combined('full_name', ['first_name', 'last_name'])->globalSearch()])->definition();
})->throws(InvalidDefinition::class, 'searchableItems');

it('refuses a table with no columns', function (): void {
    auraTable([])->definition();
})->throws(InvalidDefinition::class, 'at least one column');

it('serves the cached definition byte-for-byte', function (): void {
    CachedTable::$builds = 0;

    $fresh = (new CachedTable)->definition();
    $cached = (new CachedTable)->definition();

    expect(json_encode($cached))->toBe(json_encode($fresh))
        ->and(CachedTable::$builds)->toBe(1);
});

it('serves the cached whitelist unchanged too', function (): void {
    CachedTable::$builds = 0;

    $fresh = (new CachedTable)->permissions();
    $cached = (new CachedTable)->permissions();

    expect($cached)->toEqual($fresh)
        ->and($cached->sortable)->toBe(['last_name', 'balance'])
        ->and(CachedTable::$builds)->toBe(1);
});

it('rebuilds after the cache is dropped', function (): void {
    CachedTable::$builds = 0;

    (new CachedTable)->definition();
    (new CachedTable)->forgetCache();
    (new CachedTable)->definition();

    expect(CachedTable::$builds)->toBe(2);
});

it('rebuilds rather than trusting a cache entry of the wrong shape', function (): void {
    CachedTable::$builds = 0;

    Cache::put((new CachedTable)->cacheKey(), 'not an array', 60);

    expect((new CachedTable)->definition())->toHaveKey('header')
        ->and(CachedTable::$builds)->toBe(1);
});

it('does not widen the whitelist from a tampered cache entry', function (): void {
    Cache::put((new CachedTable)->cacheKey(), [
        'definition' => ['header' => ['rows' => [['cells' => [['content' => 'x', 'field' => 'x']]]]]],
        'fields' => ['sortable' => ['last_name', ['nested'], 42], 'searchable' => 'everything'],
    ], 60);

    $permissions = (new CachedTable)->permissions();

    expect($permissions->sortable)->toBe(['last_name'])
        ->and($permissions->searchable)->toBe([]);
});

it('starts from a whitelist that allows nothing', function (): void {
    $permissions = auraTable([Column::make('last_name')])->permissions();

    expect($permissions)->toEqual(FieldPermissions::none());
});

it('keeps the selection column out of the whitelist', function (): void {
    $permissions = (new UserTable)->permissions();

    expect($permissions->allowsSort('id'))->toBeFalse()
        ->and($permissions->allowsFilter('id'))->toBeFalse();
});

it('passes the selected ids through to the caller', function (): void {
    $request = AuraRequest::fromArray(
        ['page' => 1, 'paginate' => 10, 'selected' => [1, 3]],
        (new UserTable)->permissions(),
    );

    expect($request->selected)->toBe([1, 3]);
});
