<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use TamasLabs\Aura\Cell\CellRules;
use TamasLabs\Aura\Cell\Condition;
use TamasLabs\Aura\Cell\Link;
use TamasLabs\Aura\Cell\Text;
use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Response\RowPermissions;
use TamasLabs\Aura\Table\Action;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\ColumnGroup;
use TamasLabs\Aura\Tests\Fixtures\Status;
use TamasLabs\Aura\Tests\Fixtures\TypedCompany;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;

beforeEach(function (): void {
    $acme = TypedCompany::create(['name' => 'Acme', 'tier' => 'paid']);

    foreach ([
        ['Ada', 'Lovelace', Status::Active],
        ['Alan', 'Turing', Status::Suspended],
        ['Grace', 'Hopper', Status::Active],
    ] as [$first, $last, $status]) {
        TypedUser::create([
            'company_id' => $acme->getKey(), 'first_name' => $first, 'last_name' => $last,
            'status' => $status, 'balance' => 100, 'created_at' => '2024-01-01 10:00:00',
        ]);
    }
});

/**
 * Only the active users may be edited — the rule every test here gates on.
 */
function auraActiveOnly(): Closure
{
    return static fn (TypedUser $user): bool => $user->status === Status::Active;
}

/**
 * One page of a table, as the browser receives it.
 *
 * @param  list<Column|ColumnGroup>  $columns
 * @return array<string, mixed>
 */
function auraPage(array $columns, ?string $resource = 'admin/users'): array
{
    return auraTable($columns, resource: $resource)
        ->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));
}

/**
 * The values one row field takes across the page, in order.
 *
 * @param  array<string, mixed>  $response
 * @return list<mixed>
 */
function auraFlags(array $response, string $field): array
{
    return array_map(
        static fn (mixed $item): mixed => is_array($item) ? ($item[$field] ?? '(absent)') : '(not a row)',
        array_values(auraDigArray($response, 'items')),
    );
}

// ---------------------------------------------------------------------------
// 5c.1 — the emitted shape
// ---------------------------------------------------------------------------

it('gates a cell on a hidden flag, with no else beneath it', function (): void {
    $config = auraDigArray(
        auraTable(
            [Column::make('last_name'), Column::actions('id', Action::edit()->allowedWhen(auraActiveOnly()))],
            resource: 'admin/users',
        )->definition(),
        'body', 'columnConfigs', 'edit_icon',
    );

    // The gate is the whole root: the type Aura dispatches on, the flag it
    // reads, and one branch. No `else` — that absence is the mechanism, not an
    // omission, and everything the cell renders is inside the branch.
    expect($config)->toBe([
        'type' => 'icon',
        'key' => '_allowed_edit_icon',
        'if' => [[
            'true' => true,
            'variant' => 'edit',
            'alt' => 'Edit',
            'title' => 'Edit',
            'icon' => 'edit',
            'route' => 'admin/users/{id}/edit',
            // INV13/INV14: the route placeholder lives in the *branch*. At the
            // root `key` is the condition selector and `stripLogicProps`
            // removes it, so an icon whose key stayed there would render
            // without its link — silently.
            'key' => 'id',
        ]],
    ]);
});

it('offers a whole page of flags, denied rows included', function (): void {
    $response = auraPage([
        Column::make('last_name'),
        Column::actions('id', Action::edit()->allowedWhen(auraActiveOnly())),
    ]);

    // 5c.2: written for every row. A missing flag also hides the cell, so a
    // gate that silently stopped running would look exactly like a denial.
    expect(auraFlags($response, '_allowed_edit_icon'))->toBe([true, false, true]);
});

it('keeps the flag out of the header and the whitelist', function (): void {
    $table = auraTable(
        [Column::make('last_name')->sortable(), Column::actions('id', Action::edit()->allowedWhen(auraActiveOnly()))],
        resource: 'admin/users',
    );

    expect(json_encode($table->definition()['header'], JSON_THROW_ON_ERROR))
        ->not->toContain(RowPermissions::PREFIX)
        ->and($table->permissions()->sortable)->toBe(['last_name']);
});

it('sends a response Aura accepts', function (): void {
    $response = auraPage([
        Column::make('last_name'),
        Column::actions('id', Action::show(), Action::edit()->allowedWhen(auraActiveOnly())),
    ]);

    assertMatchesAuraResponseSchema(auraObject($response));
});

// ---------------------------------------------------------------------------
// 5c.4 — a real bool, whatever the callback returns
// ---------------------------------------------------------------------------

it('writes a real bool into the flag', function (mixed $returned, bool $expected): void {
    $response = auraPage([
        Column::make('last_name'),
        Column::actions('id', Action::edit()->allowedWhen(static fn (): mixed => $returned)),
    ]);

    $flags = auraFlags($response, '_allowed_edit_icon');

    // INV4: `true` is an exact comparison (`fieldValue === true`), so a tinyint
    // 1 or a driver's "1" would deny every row without a word.
    expect($flags[0])->toBe($expected)
        ->and(json_encode($flags[0], JSON_THROW_ON_ERROR))->toBe($expected ? 'true' : 'false');
})->with([
    'tinyint one' => [1, true],
    'tinyint zero' => [0, false],
    'string one' => ['1', true],
    'empty string' => ['', false],
    'null' => [null, false],
    'true' => [true, true],
]);

// ---------------------------------------------------------------------------
// 5c.5 — prepared once for the page
// ---------------------------------------------------------------------------

it('prepares a batched gate once for the whole page', function (): void {
    $pages = 0;
    $rows = 0;

    $response = auraPage([
        Column::make('last_name'),
        Column::actions('id', Action::edit()->allowedWhenAll(
            function (Collection $page) use (&$pages, &$rows): Closure {
                $pages++;

                $allowed = TypedUser::query()
                    ->whereIn('id', $page->modelKeys())
                    ->where('status', Status::Active->value)
                    ->pluck('id')
                    ->flip();

                return function (TypedUser $user) use ($allowed, &$rows): bool {
                    $rows++;

                    return $allowed->has($user->id);
                };
            },
        )),
    ]);

    expect($pages)->toBe(1)
        ->and($rows)->toBe(3)
        ->and(auraFlags($response, '_allowed_edit_icon'))->toBe([true, false, true]);
});

it('runs no query of its own, in either form', function (): void {
    $queries = [];

    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    auraPage([
        Column::make('last_name'),
        Column::actions('id', Action::edit()->allowedWhen(auraActiveOnly())),
    ]);

    $withGate = count($queries);
    $queries = [];

    auraPage([
        Column::make('last_name'),
        Column::actions('id', Action::edit()->title('Edit')),
    ]);

    // The per-row form is handed the model that is already in memory, so the
    // gate costs the page exactly nothing.
    expect($withGate)->toBe(count($queries));
});

// ---------------------------------------------------------------------------
// 5c.3 — what the gate composes with, and what it cannot be reached past
// ---------------------------------------------------------------------------

it('keeps hiding a cell whose own branches carry an else', function (): void {
    $config = auraDigArray(
        auraTable([
            Column::make('status')->as(
                Text::make()
                    ->allowedWhen(auraActiveOnly())
                    ->when(Condition::eq('active'), fn (Text $text): Text => $text->value('Live'))
                    ->otherwise(fn (Text $text): Text => $text->value('—')),
            ),
        ])->definition(),
        'body', 'columnConfigs', 'status',
    );

    // The gate is the outer level, so the caller's own `else` answers for the
    // rows the gate *allowed* and can never answer for the rows it denied.
    // This is why `allowedWhen()` and `otherwise()` are not refused as a pair.
    expect($config['key'])->toBe('_allowed_status')
        ->and($config)->not->toHaveKey('else');

    $branch = auraDigArray($config, 'if', 0);

    expect($branch['true'])->toBeTrue()
        ->and($branch['key'])->toBe('status')
        ->and(auraDigArray($branch, 'else'))->toHaveKey('value');
});

it('refuses a gate that would push the nesting past what Aura resolves', function (): void {
    // Five `when()` levels, built from the leaf outwards because `when()` hands
    // the branch out rather than taking one.
    $nest = static function (int $levels): Closure {
        $build = static fn (Text $text): Text => $text->value('leaf');

        for ($level = 0; $level < $levels; $level++) {
            $inner = $build;
            $build = static fn (Text $text): Text => $text->when(Condition::eq('x'), $inner);
        }

        return $build;
    };

    $five = static function () use ($nest): Text {
        $text = Text::make();
        $nest(5)($text);

        return $text;
    };

    // Five levels resolve; the gate would be a sixth, and Aura truncates past
    // its own cap with nothing said outside the error store.
    expect($five()->depth())->toBe(5)
        ->and(fn (): array => auraTable([Column::make('status')->as($five())])->definition())
        ->not->toThrow(InvalidDefinition::class);

    expect(fn (): array => auraTable([
        Column::make('status')->as($five()->allowedWhen(auraActiveOnly())),
    ])->definition())->toThrow(InvalidDefinition::class, 'nest 6 levels deep');
});

it('leaves the cell rules outside the gate', function (): void {
    $config = auraDigArray(
        auraTable([
            Column::make('status')
                ->as(Text::make()->value('Status')->allowedWhen(auraActiveOnly()))
                ->rules(CellRules::make()->background('#eee')),
        ])->definition(),
        'body', 'columnConfigs', 'status',
    );

    // `cellRules` is not content: Aura reads it off `columnConfigs[column.key]`
    // and styles the `<td>` whether or not anything renders inside it.
    expect($config)->toHaveKey('cellRules')
        ->and(auraDigArray($config, 'if', 0))->not->toHaveKey('cellRules');
});

// ---------------------------------------------------------------------------
// The gate on an action
// ---------------------------------------------------------------------------

it('escalates an action that is gated', function (): void {
    $definition = auraTable(
        [Column::make('last_name'), Column::actions('id', Action::show(), Action::edit()->allowedWhen(auraActiveOnly()))],
        resource: 'admin/users',
    )->definition();

    // A generated configuration carries no condition, so a gated action cannot
    // be left to the browser. An ungated one beside it still is.
    expect(auraDigArray($definition, 'body', 'columnConfigs'))->toHaveKey('edit_icon')
        ->and(auraDigArray($definition, 'body', 'columnConfigs'))->not->toHaveKey('show_icon');
});

it('gates a destroy on the modal, not on the glyph inside it', function (): void {
    $config = auraDigArray(
        auraTable(
            [Column::make('last_name'), Column::actions('id', Action::destroy()->allowedWhen(auraActiveOnly()))],
            resource: 'admin/users',
        )->definition(),
        'body', 'columnConfigs', 'destroy_icon',
    );

    expect($config['type'])->toBe('modal')
        ->and($config['key'])->toBe('_allowed_destroy_icon');

    $branch = auraDigArray($config, 'if', 0);

    // A denied row loses the trigger outright; gating the content instead would
    // leave a glyph that opens an empty modal.
    expect($branch['id'])->toBe('destroyModal')
        ->and($branch['route'])->toBe('admin/users/{id}/destroy')
        ->and(auraDigArray($branch, 'content'))->not->toHaveKey('if');
});

it('gates each action of a column on its own flag', function (): void {
    $response = auraPage([
        Column::make('last_name'),
        Column::actions(
            'id',
            Action::edit()->allowedWhen(auraActiveOnly()),
            Action::destroy()->allowedWhen(static fn (TypedUser $user): bool => $user->last_name === 'Turing'),
        ),
    ]);

    expect(auraFlags($response, '_allowed_edit_icon'))->toBe([true, false, true])
        ->and(auraFlags($response, '_allowed_destroy_icon'))->toBe([false, true, false]);
});

// ---------------------------------------------------------------------------
// The gate on a cell configuration
// ---------------------------------------------------------------------------

it('gates a column own renderer', function (): void {
    $response = auraPage([
        Column::make('last_name')->as(Link::make()->route('users/{id}')->allowedWhen(auraActiveOnly())),
    ], resource: null);

    expect(auraFlags($response, '_allowed_last_name'))->toBe([true, false, true])
        ->and(auraDigArray($response, 'body', 'columnConfigs', 'last_name'))
        ->toHaveKey('if');
});

it('gates one member field of a multi-field column', function (): void {
    $response = auraPage([
        Column::combined('name', ['first_name', 'last_name'])
            ->configure('last_name', Link::make()->route('users/{id}')->allowedWhen(auraActiveOnly())),
    ], resource: null);

    expect(auraFlags($response, '_allowed_last_name'))->toBe([true, false, true])
        ->and(auraDig($response, 'body', 'columnConfigs', 'last_name', 'key'))
        ->toBe('_allowed_last_name');
});

it('names the flag after the field, with dots flattened', function (): void {
    // A dotted flag would send Aura's `resolveValue` looking for `name` inside
    // an `_allowed_company` that no row carries: every row denied, silently.
    expect(RowPermissions::fieldFor('company.name'))->toBe('_allowed_company_name');

    $response = auraPage([
        Column::make('company.name')->as(Link::make()->route('companies/{id}')->allowedWhen(auraActiveOnly())),
    ], resource: null);

    expect(auraFlags($response, '_allowed_company_name'))->toBe([true, false, true]);
});

it('gates a column inside a group', function (): void {
    $response = auraPage([
        ColumnGroup::make('Who', [
            Column::make('first_name'),
            Column::make('last_name')->as(Link::make()->route('users/{id}')->allowedWhen(auraActiveOnly())),
        ]),
    ], resource: null);

    expect(auraFlags($response, '_allowed_last_name'))->toBe([true, false, true]);
});

// ---------------------------------------------------------------------------
// Nothing gated costs nothing
// ---------------------------------------------------------------------------

it('adds no field to a table that gates nothing', function (): void {
    $response = auraPage([Column::make('last_name'), Column::actions('id', Action::edit())]);

    expect(json_encode(auraDigArray($response, 'items'), JSON_THROW_ON_ERROR))
        ->not->toContain(RowPermissions::PREFIX);
});

it('still applies the gates when the definition comes from the cache', function (): void {
    $page = static fn (): array => auraTable(
        [Column::make('last_name'), Column::actions('id', Action::edit()->allowedWhen(auraActiveOnly()))],
        resource: 'admin/users',
        cached: true,
    )->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    $first = $page();
    $second = $page();

    // The cache holds the flag's *name*, written into the definition as a
    // condition; the gate that fills it is a closure and is collected fresh.
    expect(auraDig($second, 'body', 'columnConfigs', 'edit_icon', 'key'))->toBe('_allowed_edit_icon')
        ->and(auraFlags($second, '_allowed_edit_icon'))
        ->toBe(auraFlags($first, '_allowed_edit_icon'))
        ->toBe([true, false, true]);
});

it('hides the cell when a cached definition outlives the gate that filled it', function (): void {
    $gated = auraTable(
        [Column::make('last_name'), Column::actions('id', Action::edit()->allowedWhen(auraActiveOnly()))],
        resource: 'admin/users',
        cached: true,
    );

    $gated->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    // The same table class, its columns changed under a warm cache: the
    // definition still names the flag, nothing fills it any more. An absent
    // field is not `true`, so every cell stays hidden — the drift can only
    // fail closed.
    $drifted = auraTable(
        [Column::make('last_name'), Column::actions('id', Action::edit()->title('Edit'))],
        resource: 'admin/users',
        cached: true,
    )->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    expect(auraDig($drifted, 'body', 'columnConfigs', 'edit_icon', 'key'))->toBe('_allowed_edit_icon')
        ->and(auraFlags($drifted, '_allowed_edit_icon'))->toBe(['(absent)', '(absent)', '(absent)']);
});

// ---------------------------------------------------------------------------
// Refusals
// ---------------------------------------------------------------------------

it('refuses two gates writing one flag', function (): void {
    expect(fn (): mixed => auraTable([
        Column::make('last_name')->as(Link::make()->route('a/{id}')->allowedWhen(auraActiveOnly())),
        Column::combined('name', ['first_name', 'last_name'])
            ->configure('last_name', Link::make()->route('b/{id}')->allowedWhen(auraActiveOnly())),
    ])->rowPermissions())->toThrow(InvalidDefinition::class, '_allowed_last_name');
});

it('refuses a batched gate that does not hand back a test', function (): void {
    expect(fn (): array => auraPage([
        Column::make('last_name'),
        Column::actions('id', Action::edit()->allowedWhenAll(static fn (): string => 'yes')),
    ]))->toThrow(InvalidDefinition::class, 'returned string, not a callable');
});

it('refuses a gate on a column that names no field', function (): void {
    expect(fn (): mixed => auraTable([
        Column::selection()->as(Link::make()->route('a/{id}')->allowedWhen(auraActiveOnly())),
    ])->rowPermissions())->toThrow(InvalidDefinition::class, 'names no field');
});

// ---------------------------------------------------------------------------
// 5c.6 — the same policy the route is protected by
// ---------------------------------------------------------------------------

it('reads the policy the route would', function (): void {
    Gate::define('update-user', static fn (?object $user, TypedUser $row): bool => $row->status === Status::Active);

    $response = auraPage([
        Column::make('last_name'),
        Column::actions('id', Action::edit()->allowedWhen(
            static fn (TypedUser $row): bool => Gate::allows('update-user', $row),
        )),
    ]);

    // Hiding is not authorisation: the route is in the payload for every row,
    // and this only stops the table offering what the route would refuse.
    expect(auraFlags($response, '_allowed_edit_icon'))->toBe([true, false, true])
        ->and(auraDigArray($response, 'body', 'columnConfigs', 'edit_icon', 'if', 0))
        ->toHaveKey('route');
});
