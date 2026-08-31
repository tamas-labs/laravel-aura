<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use TamasLabs\Aura\Cell\Badge;
use TamasLabs\Aura\Cell\Button;
use TamasLabs\Aura\Cell\CellConfig;
use TamasLabs\Aura\Cell\CellRules;
use TamasLabs\Aura\Cell\Condition;
use TamasLabs\Aura\Cell\Icon;
use TamasLabs\Aura\Cell\Link;
use TamasLabs\Aura\Cell\Modal;
use TamasLabs\Aura\Cell\Progress;
use TamasLabs\Aura\Cell\Reference;
use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\TableBlueprint;
use TamasLabs\Aura\Tests\Fixtures\Status;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;

beforeEach(function (): void {
    DB::table('companies')->insert(['id' => 1, 'name' => 'Acme', 'tier' => 'paid']);

    DB::table('users')->insert([
        'id' => 1,
        'first_name' => 'Anna',
        'last_name' => 'Kovács',
        'email' => 'anna@example.com',
        'status' => 'active',
        'balance' => 1234.50,
        'company_id' => 1,
        'created_at' => '2026-01-01 10:00:00',
    ]);
});

/**
 * The `columnConfigs` block of a built definition.
 *
 * @param  AuraTable<TypedUser>  $table
 * @return array<array-key, mixed>
 */
function auraConfigs(AuraTable $table): array
{
    return auraDigArray($table->definition(), 'body', 'columnConfigs');
}

it('keys a renderer by the field the column reads', function (): void {
    $configs = auraConfigs(auraTable([
        Column::make('status')->as(Badge::fromEnum(Status::class)),
    ]));

    expect(array_keys($configs))->toBe(['status'])
        ->and(auraDigArray($configs, 'status')['type'])->toBe('badge');
});

it('refuses a renderer on a column whose key and field disagree', function (): void {
    // This is what makes the question moot everywhere else. The schema says
    // `columnConfigs` is keyed by the header cell's `key`; TableBodyRow.tsx
    // reads `columnConfigs[column.field]` for the renderer and
    // `columnConfigs[column.key]` for the cellRules. A column where the two
    // differ would need both entries and would get whichever the lookup
    // happened to reach, so the definition is refused instead.
    auraTable([Column::make('status')->key('state')->as(Badge::make())])->definition();
})->throws(InvalidDefinition::class, 'only half of it would ever be read');

it('refuses cell rules on a column whose key and field disagree, for the same reason', function (): void {
    auraTable([Column::make('status')->key('state')->rules(
        CellRules::make()->when(Condition::eq('x'), fn (CellRules $r): CellRules => $r->opacity(0.5))
    )])->definition();
})->throws(InvalidDefinition::class, 'only half of it would ever be read');

it('refuses a single renderer on a column that reads several fields', function (): void {
    auraTable([
        Column::combined('name', ['first_name', 'last_name'])->as(Reference::make()),
    ])->definition();
})->throws(InvalidDefinition::class, 'nowhere to attach');

it('configures a combined column one field at a time', function (): void {
    // Aura builds a segment per member field and looks each up by that name.
    $configs = auraConfigs(auraTable([
        Column::combined('name', ['first_name', 'last_name'])
            ->configure('last_name', Reference::make()->uppercase()),
    ]));

    expect(array_keys($configs))->toBe(['last_name'])
        ->and(auraDigArray($configs, 'last_name')['uppercase'])->toBeTrue();
});

it('refuses to configure a field the column does not read', function (): void {
    auraTable([
        Column::combined('name', ['first_name', 'last_name'])
            ->configure('email', Reference::make()),
    ])->definition();
})->throws(InvalidDefinition::class, 'has no field "email" to configure');

it('hands the renderer the formatting the heading would have applied', function (): void {
    $configs = auraConfigs(auraTable([
        Column::make('balance')->as(Reference::make()->color('success')),
    ]));

    // `currency` is inferred from the decimal cast onto the header cell; without
    // this the cell would lose it the moment a renderer was attached.
    expect(auraDigArray($configs, 'balance')['currency'])->toBeTrue();
});

it('puts cell rules where Aura reads them from — under the column key', function (): void {
    $configs = auraConfigs(auraTable([
        Column::make('balance')->rules(
            CellRules::make()->when(Condition::lt(0), fn (CellRules $r): CellRules => $r->background('#fee'))
        ),
    ]));

    $entry = auraDigArray($configs, 'balance');

    // No renderer was asked for, so the stand-in is a `reference` — which is
    // what the cell was already doing, formatter chain included.
    expect($entry['type'])->toBe('reference')
        ->and($entry['field'])->toBe('balance')
        ->and($entry['currency'])->toBeTrue()
        ->and(auraDigArray($entry, 'cellRules')['key'])->toBe('balance');
});

it('attaches cell rules to the renderer the column already has', function (): void {
    $configs = auraConfigs(auraTable([
        Column::make('status')
            ->as(Badge::fromEnum(Status::class))
            ->rules(CellRules::make()->when(Condition::eq('suspended'), fn (CellRules $r): CellRules => $r->opacity(0.5))),
    ]));

    $entry = auraDigArray($configs, 'status');

    expect($entry['type'])->toBe('badge')
        ->and($entry)->toHaveKey('cellRules');
});

it('carries cell rules on a combined column too', function (): void {
    $configs = auraConfigs(auraTable([
        Column::combined('name', ['first_name', 'last_name'])
            ->rules(CellRules::make()->on('status')->when(Condition::eq('suspended'), fn (CellRules $r): CellRules => $r->opacity(0.5))),
    ]));

    $entry = auraDigArray($configs, 'name');

    expect($entry['fields'])->toBe(['first_name', 'last_name'])
        ->and($entry)->toHaveKey('cellRules');
});

it('emits row rules that name the field they read', function (): void {
    $table = auraTable(
        [Column::make('last_name')],
        rowRules: CellRules::make()
            ->on('status')
            ->when(Condition::eq('suspended'), fn (CellRules $r): CellRules => $r->opacity(0.5)),
    );

    $rules = auraDigArray($table->definition(), 'body', 'rowRules');

    expect($rules['key'])->toBe('status')
        ->and(auraDigArray($rules, 'if', 0)['opacity'])->toBe(0.5);
});

it('refuses row rules with no field to read, since there is no column to borrow one from', function (): void {
    auraTable(
        [Column::make('last_name')],
        rowRules: CellRules::make()->when(Condition::isTrue(), fn (CellRules $r): CellRules => $r->opacity(0.5)),
    )->definition();
})->throws(InvalidDefinition::class, 'needs the field its conditions read');

it('sends a decimal as a number when a condition compares it as one', function (): void {
    // Laravel casts `balance` to a decimal string. Aura's `gt` needs
    // `typeof === 'number'` on both sides and is silently false otherwise, so
    // this branch would never match without the coercion.
    $payload = auraTable([
        Column::make('balance')->as(
            Reference::make()->when(Condition::gt(1000), fn (Reference $r): Reference => $r->color('success'))
        ),
    ])->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    expect(auraDig($payload, 'items', 0, 'balance'))->toBe(1234.5);

    assertMatchesAuraResponseSchema(auraObject($payload));
});

it('leaves the decimal string alone when nothing compares it numerically', function (): void {
    $payload = auraTable([Column::make('balance')])
        ->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    expect(auraDig($payload, 'items', 0, 'balance'))->toBe('1234.50');
});

it('does not mistake a date for a number', function (): void {
    // Aura compares dates with the same operators, parsing both sides; casting
    // '2026-01-01' to a float would turn it into 2026.
    $payload = auraTable([
        Column::make('created_at')->as(
            Reference::make()->when(Condition::gt('2025-01-01'), fn (Reference $r): Reference => $r->italic())
        ),
    ])->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    expect(auraDig($payload, 'items', 0, 'created_at'))->toBeString();
});

it('coerces a bar value even without a condition, because a bar is a number', function (): void {
    $payload = auraTable([Column::make('balance')->as(Progress::make()->max(2000))])
        ->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    expect(auraDig($payload, 'items', 0, 'balance'))->toBe(1234.5);
});

it('serves a whole table with renderers, and Aura would accept it', function (): void {
    $payload = auraTable([
        Column::selection(),
        Column::combined('name', ['first_name', 'last_name'])->reference('last_name')->sortable(),
        Column::make('email')->as(Reference::make()->lowercase()),
        Column::make('status')->filterable()->as(Badge::fromEnum(Status::class)),
        Column::make('balance')->sortable()->as(
            Reference::make()->when(Condition::lt(0), fn (Reference $r): Reference => $r->color('danger'))
        ),
        Column::make('edit')->content(null)->as(Icon::make('pencil')->route('users.{id}.edit')->alt('Szerkesztés')),
        Column::make('delete')->content(null)->as(Modal::destroy()->route('users.{id}.destroy')->icon('trash', 'danger')),
    ])->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    assertMatchesAuraResponseSchema(auraObject($payload));

    expect(array_keys(auraDigArray($payload, 'body', 'columnConfigs')))
        ->toBe(['email', 'status', 'balance', 'edit', 'delete']);
});

it('refuses two columns rendering the same field, which would overwrite each other', function (): void {
    // columnConfigs is one flat map keyed by field, so the second entry does not
    // sit beside the first — it replaces it, and the losing column silently
    // renders the winner's configuration.
    auraTable([
        Column::combined('name', ['first_name', 'last_name'])->reference('last_name')
            ->configure('last_name', Reference::make()->uppercase()),
        Column::make('last_name')->as(Badge::make()),
    ])->definition();
})->throws(InvalidDefinition::class, 'already has a cell configuration');

it('keeps the renderers out of the field whitelist', function (): void {
    // A renderer says how a cell looks, never what the server will accept.
    $table = auraTable([
        Column::make('status')->as(Badge::fromEnum(Status::class)),
        Column::make('last_name')->sortable(),
    ]);

    expect($table->permissions()->sortable)->toBe(['last_name'])
        ->and($table->permissions()->filterable)->toBe([]);
});

it('survives the cache with its renderers and its numeric fields intact', function (): void {
    $table = auraTable([
        Column::make('balance')->as(
            Reference::make()->when(Condition::gt(1000), fn (Reference $r): Reference => $r->color('success'))
        ),
    ]);

    $blueprint = $table->blueprint();
    $restored = TableBlueprint::fromArray($blueprint->toArray());

    expect($restored->definition)->toBe($blueprint->definition)
        ->and($restored->numericFields)->toBe(['balance']);
});

it('refuses conditional cell rules on a multi-field column with no field to read', function (): void {
    // Without ->on(), the conditions would be keyed by the column key — a name
    // the rows do not carry. Aura reads undefined, every condition is false, and
    // the cell is never styled, with nothing said anywhere.
    auraTable([
        Column::combined('name', ['first_name', 'last_name'])
            ->rules(CellRules::make()->when(Condition::eq('suspended'), fn (CellRules $r): CellRules => $r->opacity(0.5))),
    ])->definition();
})->throws(InvalidDefinition::class, 'no single field to read');

it('accepts unconditional cell rules on a multi-field column, which emit no key', function (): void {
    // Nothing to fall back on and nothing that needs it: an unconditional rule
    // set is plain formatting, and `conditionals()` emits no `key` for it.
    $configs = auraConfigs(auraTable([
        Column::combined('name', ['first_name', 'last_name'])
            ->rules(CellRules::make()->opacity(0.5)),
    ]));

    $rules = auraDigArray(auraDigArray($configs, 'name'), 'cellRules');

    expect($rules)->toBe(['opacity' => 0.5])
        ->and($rules)->not->toHaveKey('key');
});

it('still accepts cell rules on a multi-field column that name their field', function (): void {
    $configs = auraConfigs(auraTable([
        Column::combined('name', ['first_name', 'last_name'])
            ->rules(CellRules::make()->on('status')->when(Condition::eq('suspended'), fn (CellRules $r): CellRules => $r->opacity(0.5))),
    ]));

    expect(auraDigArray(auraDigArray($configs, 'name'), 'cellRules')['key'])->toBe('status');
});

it('does not default a field onto a configuration that carries a fixed value', function (string $type, CellConfig $config): void {
    // Every renderer that reads a row value reads `field` first and falls back
    // to `value` — `renderBadgeNode`, `renderProgressNode` and
    // `action-node-helpers.ts` all do. So a defaulted field beside a fixed
    // label is not a fallback: the field wins and the label never appears.
    $configs = auraConfigs(auraTable([Column::make('email')->as($config)]));

    $resolved = auraDigArray($configs, 'email');

    expect($resolved['type'])->toBe($type)
        ->and($resolved)->not->toHaveKey('field');
})->with([
    'link' => ['link', fn (): CellConfig => Link::make()->value('Details')->route('users/{id}')],
    'button' => ['button', fn (): CellConfig => Button::make('Open')->route('users/{id}')],
    'badge' => ['badge', fn (): CellConfig => Badge::make()->value('n/a')],
]);

it('still defaults the field onto a reference, whose renderer reads value first', function (): void {
    // `renderReferenceNode` checks `value` before `field`, so the column's
    // field beside it is inert rather than harmful — and dropping it would
    // change the payload for a rule that changes nothing.
    $configs = auraConfigs(auraTable([Column::make('email')->as(Reference::make()->value('n/a'))]));

    expect(auraDigArray($configs, 'email')['field'])->toBe('email');
});
