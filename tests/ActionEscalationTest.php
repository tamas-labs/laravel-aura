<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Table\Action;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Tests\Fixtures\Status;
use TamasLabs\Aura\Tests\Fixtures\TypedCompany;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;

beforeEach(function (): void {
    $acme = TypedCompany::create(['name' => 'Acme', 'tier' => 'paid']);

    TypedUser::create([
        'company_id' => $acme->getKey(), 'first_name' => 'Ada', 'last_name' => 'Lovelace',
        'status' => Status::Active, 'balance' => 100, 'created_at' => '2024-01-01 10:00:00',
    ]);

    Route::get('/admin/users/{user}/edit', fn (): string => '')->name('admin.users.edit');
    Route::get('/companies/{company}/users/{user}', fn (): string => '')->name('companies.users.show');
    Route::get('/admin/users/create', fn (): string => '')->name('admin.users.create');
    Route::get('/exports/{user}/report.pdf', fn (): string => '')->name('users.report');

    // `->name()` is called after the route is added to the collection, so the
    // name lookup table does not yet know about any of them.
    Route::getRoutes()->refreshNameLookups();
});

/**
 * The `body.columnConfigs` entry for one action field.
 *
 * @param  array<string, mixed>  $definition
 * @return array<array-key, mixed>
 */
function auraActionConfig(array $definition, string $field): array
{
    return auraDigArray($definition, 'body', 'columnConfigs', $field);
}

// ---------------------------------------------------------------------------
// 5b.1 — one call surface, two outputs
// ---------------------------------------------------------------------------

it('stays in convention mode until something is customised', function (): void {
    $definition = auraTable(
        [Column::actions('id', Action::edit())],
        resource: 'admin/users',
    )->definition();

    // A resource on the table is not itself a customisation: the browser still
    // builds the route, from its own urlParameter.
    expect($definition)->not->toHaveKey('body');
});

it('emits the whole configuration once something is customised', function (): void {
    $definition = auraTable(
        [Column::actions('id', Action::edit()->title('Edit this user'))],
        resource: 'admin/users',
    )->definition();

    expect(auraActionConfig($definition, 'edit_icon'))->toEqual([
        'type' => 'icon',
        'variant' => 'edit',
        'alt' => 'Edit',
        'title' => 'Edit this user',
        'icon' => 'edit',
        'route' => 'admin/users/{id}/edit',
        'key' => 'id',
    ]);
});

it('escalates one action without dragging the others with it', function (): void {
    $definition = auraTable(
        [Column::actions('id', Action::show(), Action::edit()->icon('pencil'), Action::destroy())],
        resource: 'admin/users',
    )->definition();

    $configs = auraDigArray($definition, 'body', 'columnConfigs');

    // The column still offers three actions; only the customised one costs a
    // configuration. The other two are still the browser's to build.
    expect(array_keys($configs))->toBe(['edit_icon'])
        ->and(auraCell($definition, 'id')['fields'] ?? null)
        ->toBe(['show_icon', 'edit_icon', 'destroy_icon']);
});

it('escalates on any customisation at all', function (string $method, mixed $argument): void {
    $action = Action::edit();
    $action->{$method}($argument);

    expect($action->isEscalated())->toBeTrue();
})->with([
    'icon' => ['icon', 'pencil'],
    'variant' => ['variant', 'warning'],
    'label' => ['label', 'Edit'],
    'title' => ['title', 'Edit this user'],
    'alt' => ['alt', 'Edit'],
    'route' => ['route', 'users/{id}/edit'],
    'routeName' => ['routeName', 'admin.users.edit'],
    'modal' => ['modal', 'confirmModal'],
]);

it('does not escalate on choosing a shape', function (string $method, string $field): void {
    $action = Action::edit();
    $action->{$method}();

    expect($action->isEscalated())->toBeFalse()
        ->and($action->field())->toBe($field);
})->with([
    ['asIcon', 'edit_icon'],
    ['asLink', 'edit_link'],
    ['asButton', 'edit_button'],
]);

it('reaches the keys that have no method of their own', function (): void {
    $definition = auraTable(
        [Column::actions('id', Action::edit()->asButton()->set('size', 'sm')->set('rounded', true))],
        resource: 'admin/users',
    )->definition();

    $config = auraActionConfig($definition, 'edit_button');

    expect($config['size'] ?? null)->toBe('sm')
        ->and($config['rounded'] ?? null)->toBeTrue();
});

it('refuses a structural key through the escape hatch', function (): void {
    auraTable(
        [Column::actions('id', Action::edit()->set('key', 'sneaky'))],
        resource: 'admin/users',
    )->definition();
})->throws(InvalidDefinition::class, 'cannot set "key" directly');

it('produces a response Aura would accept', function (): void {
    $response = auraTable(
        [
            Column::make('last_name')->sortable(),
            Column::actions(
                'id',
                Action::show()->asLink()->label('Details'),
                Action::edit()->icon('pencil')->variant('warning'),
                Action::destroy()->asButton()->variant('danger')->label('Delete'),
            ),
        ],
        resource: 'admin/users',
    )->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    assertMatchesAuraResponseSchema(auraObject($response));
});

// ---------------------------------------------------------------------------
// 5b.2 — the resource is where a server-built route comes from
// ---------------------------------------------------------------------------

it('refuses to escalate without a resource to build the route on', function (): void {
    auraTable([Column::actions('id', Action::edit()->title('Edit'))])->definition();
})->throws(InvalidDefinition::class, 'Set $resource on the table');

it('needs no resource when the action names its own route', function (): void {
    $definition = auraTable([
        Column::actions('id', Action::edit()->route('users/{id}/edit')->title('Edit')),
    ])->definition();

    expect(auraActionConfig($definition, 'edit_icon')['route'] ?? null)->toBe('users/{id}/edit');
});

it('trims the resource the way Aura trims its own base', function (): void {
    $definition = auraTable(
        [Column::actions('id', Action::show()->title('Show'))],
        resource: '/admin/users/',
    )->definition();

    expect(auraActionConfig($definition, 'show_icon')['route'] ?? null)->toBe('admin/users/{id}');
});

it('refuses a resource Aura would mangle', function (string $resource, string $message): void {
    auraTable([Column::actions('id', Action::edit()->title('Edit'))], resource: $resource)->definition();
})->throws(InvalidDefinition::class)->with([
    'absolute' => ['https://app.test/admin/users', 'siteName'],
    'dotted' => ['admin.users', 'slash'],
]);

it('checks the resource even when nothing escalates', function (): void {
    // The base is wrong whether or not this build happens to use it, and a
    // definition is cached: finding out on the request that first customises
    // an action would be finding out at random.
    auraTable([Column::make('last_name')], resource: 'admin.users')->definition();
})->throws(InvalidDefinition::class, 'cannot be used as a route base');

// ---------------------------------------------------------------------------
// 5b.3 — routes (INV2), and the icon's key (INV13)
// ---------------------------------------------------------------------------

it('refuses an absolute route', function (): void {
    Action::edit()->route('https://app.test/users/{id}/edit');
})->throws(InvalidDefinition::class, 'is absolute');

it('refuses a route name where a path belongs', function (): void {
    // The trap this guard exists for: `users.edit` is a valid Aura route that
    // resolves to `/users/edit` — a real URL, with the identifier missing.
    Action::edit()->route('admin.users.edit');
})->throws(InvalidDefinition::class, 'contains a dot');

it('resolves a named route to the path it was registered with', function (): void {
    $definition = auraTable([
        Column::actions('id', Action::edit()->routeName('admin.users.edit')),
    ])->definition();

    // `{user}` is the router's parameter name; `{id}` is the row field Aura
    // fills it from, which is the action column's key.
    expect(auraActionConfig($definition, 'edit_icon')['route'] ?? null)->toBe('admin/users/{id}/edit');
});

it('substitutes the parameters it is given and leaves one open', function (): void {
    $definition = auraTable([
        Column::actions('id', Action::show()->routeName('companies.users.show', ['company' => 7])),
    ])->definition();

    expect(auraActionConfig($definition, 'show_icon')['route'] ?? null)->toBe('companies/7/users/{id}');
});

it('lets a given parameter be a placeholder of its own', function (): void {
    $definition = auraTable([
        Column::actions('id', Action::show()->routeName('companies.users.show', ['company' => '{company_id}'])),
    ])->definition();

    // The value is a placeholder, so it is not the open parameter — Aura fills
    // it from the row like any other.
    expect(auraActionConfig($definition, 'show_icon')['route'] ?? null)
        ->toBe('companies/{company_id}/users/{id}');
});

it('resolves a named route with no parameters at all', function (): void {
    $definition = auraTable([
        Column::actions('id', Action::create()->routeName('admin.users.create')),
    ])->definition();

    expect(auraActionConfig($definition, 'create_icon')['route'] ?? null)->toBe('admin/users/create');
});

it('refuses a named route that leaves two parameters open', function (): void {
    Action::show()->routeName('companies.users.show');
})->throws(InvalidDefinition::class, 'leaves 2 parameters open ({company}, {user})');

it('refuses a route name nothing is registered under', function (): void {
    Action::edit()->routeName('admin.users.archive');
})->throws(InvalidDefinition::class, 'No route is named "admin.users.archive"');

it('refuses a named route whose own path carries a dot', function (): void {
    Action::show()->routeName('users.report');
})->throws(InvalidDefinition::class, 'exports/{user}/report.pdf');

it('gives an escalated icon the key its anchor needs', function (): void {
    // INV13: renderIconNode wraps the glyph in an <a> only when `route` AND
    // `key` are both present. Without the key the icon renders and navigates
    // nowhere, in silence.
    $definition = auraTable(
        [Column::actions('id', Action::edit()->title('Edit'))],
        resource: 'admin/users',
    )->definition();

    expect(auraActionConfig($definition, 'edit_icon')['key'] ?? null)->toBe('id');
});

it('does not give a link or a button one, because they read the route alone', function (string $shape, string $field): void {
    $action = Action::edit()->title('Edit');
    $action->{$shape}();

    $definition = auraTable([Column::actions('id', $action)], resource: 'admin/users')->definition();

    expect(auraActionConfig($definition, $field))->not->toHaveKey('key');
})->with([
    ['asLink', 'edit_link'],
    ['asButton', 'edit_button'],
]);

// ---------------------------------------------------------------------------
// 5b.4 — destroy is a modal trigger, not a link
// ---------------------------------------------------------------------------

it('wraps a destroy in the built-in confirmation modal', function (): void {
    $definition = auraTable(
        [Column::actions('id', Action::destroy()->icon('trash'))],
        resource: 'admin/users',
    )->definition();

    // The route sits on the modal and the glyph in its content: it is the
    // modal that navigates — over AJAX, into the dialog — and the trigger that
    // is merely clicked.
    expect(auraActionConfig($definition, 'destroy_icon'))->toEqual([
        'type' => 'modal',
        'id' => 'destroyModal',
        'route' => 'admin/users/{id}/destroy',
        'content' => [
            'type' => 'icon',
            'variant' => 'destroy',
            'alt' => 'Destroy',
            'title' => 'Destroy',
            'icon' => 'trash',
        ],
    ]);
});

it('opens a modal of your own when one is named', function (): void {
    $definition = auraTable(
        [Column::actions('id', Action::destroy()->modal('archiveModal'))],
        resource: 'admin/users',
    )->definition();

    expect(auraActionConfig($definition, 'destroy_icon')['id'] ?? null)->toBe('archiveModal');
});

// ---------------------------------------------------------------------------
// 5b.5 — twelve combinations, against Aura's own preprocessor
// ---------------------------------------------------------------------------

it('escalates every shape of every action the way the browser would have built it', function (string $shape, string $field, array $expected): void {
    $action = match (explode('_', $field)[0]) {
        'create' => Action::create(),
        'show' => Action::show(),
        'edit' => Action::edit(),
        default => Action::destroy(),
    };

    $action->{$shape}();

    $definition = auraTable([Column::actions('id', $action->title('X'))], resource: 'admin/users')->definition();

    expect(auraActionConfig($definition, $field))->toEqual($expected);
})->with([
    // --- icons: `icon` + `variant` rather than the resolved `class`, because
    // the registries live in the browser. `normalizeIconConfigs` resolves them
    // in the same pass, with the same two keys, to the same classes.
    ['asIcon', 'create_icon', [
        'type' => 'icon', 'variant' => 'create', 'alt' => 'Create', 'title' => 'X',
        'icon' => 'create', 'route' => 'admin/users/create', 'key' => 'id',
    ]],
    ['asIcon', 'show_icon', [
        'type' => 'icon', 'variant' => 'show', 'alt' => 'Show', 'title' => 'X',
        'icon' => 'show', 'route' => 'admin/users/{id}', 'key' => 'id',
    ]],
    ['asIcon', 'edit_icon', [
        'type' => 'icon', 'variant' => 'edit', 'alt' => 'Edit', 'title' => 'X',
        'icon' => 'edit', 'route' => 'admin/users/{id}/edit', 'key' => 'id',
    ]],
    ['asIcon', 'destroy_icon', [
        'type' => 'modal', 'id' => 'destroyModal', 'route' => 'admin/users/{id}/destroy',
        'content' => [
            'type' => 'icon', 'variant' => 'destroy', 'alt' => 'Destroy',
            'title' => 'X', 'icon' => 'destroy',
        ],
    ]],

    // --- links: the generated label is the bare prefix, lower case, exactly as
    // `preprocessLinkFields` writes it. `create` gets no key there and none here.
    ['asLink', 'create_link', [
        'type' => 'link', 'value' => 'create', 'title' => 'X', 'route' => 'admin/users/create',
    ]],
    ['asLink', 'show_link', [
        'type' => 'link', 'value' => 'show', 'title' => 'X', 'route' => 'admin/users/{id}',
    ]],
    ['asLink', 'edit_link', [
        'type' => 'link', 'value' => 'edit', 'title' => 'X', 'route' => 'admin/users/{id}/edit',
    ]],
    ['asLink', 'destroy_link', [
        'type' => 'modal', 'id' => 'destroyModal', 'route' => 'admin/users/{id}/destroy',
        'content' => ['type' => 'link', 'value' => 'destroy', 'title' => 'X'],
    ]],

    // --- buttons: `variant` is a literal Bootstrap name here, not a registry
    // key (`btn-{variant}`), and the browser resolves it from `variants[prefix]`
    // falling back to `primary`. The server has no registry, so `primary` it is.
    ['asButton', 'create_button', [
        'type' => 'button', 'value' => 'create', 'variant' => 'primary',
        'title' => 'X', 'route' => 'admin/users/create',
    ]],
    ['asButton', 'show_button', [
        'type' => 'button', 'value' => 'show', 'variant' => 'primary',
        'title' => 'X', 'route' => 'admin/users/{id}',
    ]],
    ['asButton', 'edit_button', [
        'type' => 'button', 'value' => 'edit', 'variant' => 'primary',
        'title' => 'X', 'route' => 'admin/users/{id}/edit',
    ]],
    ['asButton', 'destroy_button', [
        'type' => 'modal', 'id' => 'destroyModal', 'route' => 'admin/users/{id}/destroy',
        'content' => ['type' => 'button', 'value' => 'destroy', 'variant' => 'primary', 'title' => 'X'],
    ]],
]);
