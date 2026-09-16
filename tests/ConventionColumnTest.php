<?php

declare(strict_types=1);

use TamasLabs\Aura\Exceptions\InvalidDefinition;
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
});

// ---------------------------------------------------------------------------
// The field name, and nothing else emitted
// ---------------------------------------------------------------------------

it('names the field after the prefix and the type', function (string $type): void {
    $definition = auraTable([
        Column::convention('status', $type),
    ])->definition();

    expect(auraCell($definition, 'status_'.$type))->not->toBeNull();
})->with(['icon', 'link', 'button', 'badge', 'progress']);

it('titles the heading after the prefix alone, not the suffixed field', function (): void {
    $definition = auraTable([
        Column::convention('status', 'badge'),
    ])->definition();

    expect(auraCell($definition, 'status_badge')['content'] ?? null)->toBe('Status');
});

it('emits no body.columnConfigs entry for a convention column', function (): void {
    $definition = auraTable([
        Column::make('last_name'),
        Column::convention('status', 'badge'),
    ])->definition();

    expect($definition)->not->toHaveKey('body');
});

it('still applies ordinary column methods to the heading cell', function (): void {
    $definition = auraTable([
        Column::convention('status', 'badge')->content('State')->align('end')->width('80px'),
    ])->definition();

    $cell = auraCell($definition, 'status_badge');

    expect($cell['content'] ?? null)->toBe('State')
        ->and($cell['align'] ?? null)->toBe('end')
        ->and($cell['width'] ?? null)->toBe('80px');
});

it('produces a response Aura would accept', function (): void {
    $response = auraTable([
        Column::make('last_name'),
        Column::convention('status', 'badge'),
        Column::convention('completion', 'progress'),
    ])->respond(auraHttpRequest(['page' => 1, 'paginate' => 10]));

    assertMatchesAuraResponseSchema(auraObject($response));
});

// ---------------------------------------------------------------------------
// Sortable/searchable/filterable still work, through ->reference()
// ---------------------------------------------------------------------------

it('allows sorting a convention column that names its real field', function (): void {
    $permissions = auraTable([
        Column::convention('status', 'badge')->reference('status')->sortable(),
    ])->permissions();

    expect($permissions->sortable)->toBe(['status']);
});

// ---------------------------------------------------------------------------
// Structural — the type has to be one Aura's preprocessor recognises
// ---------------------------------------------------------------------------

it('refuses a type Aura does not auto-generate', function (): void {
    Column::convention('status', 'pill');
})->throws(InvalidDefinition::class, '"pill" is not a type');

// ---------------------------------------------------------------------------
// The four resource verbs stay Action::class's, on icon/link/button
// ---------------------------------------------------------------------------

it('refuses a resource-verb prefix on icon, link or button', function (string $type): void {
    Column::convention('edit', $type);
})->throws(InvalidDefinition::class, 'is one of Aura\'s four resource verbs')->with(['icon', 'link', 'button']);

it('allows a resource-verb prefix on badge or progress, which Aura never routes', function (string $type): void {
    $definition = auraTable([
        Column::convention('edit', $type),
    ])->definition();

    expect(auraCell($definition, 'edit_'.$type))->not->toBeNull();
})->with(['badge', 'progress']);

// ---------------------------------------------------------------------------
// Nothing may attach a configuration — that would switch the convention off
// ---------------------------------------------------------------------------

it('refuses ->as() on a convention column', function (): void {
    auraTable([
        Column::convention('status', 'badge')->as(TamasLabs\Aura\Cell\Badge::make()),
    ])->definition();
})->throws(InvalidDefinition::class, 'was built with Column::convention()');

it('refuses ->configure() on a convention column', function (): void {
    auraTable([
        Column::convention('status', 'badge')->configure('status_badge', TamasLabs\Aura\Cell\Badge::make()),
    ])->definition();
})->throws(InvalidDefinition::class, 'was built with Column::convention()');

it('refuses ->rules() on a convention column', function (): void {
    auraTable([
        Column::convention('status', 'badge')->rules(TamasLabs\Aura\Cell\CellRules::make()->background('#fee')),
    ])->definition();
})->throws(InvalidDefinition::class, 'was built with Column::convention()');

// ---------------------------------------------------------------------------
// Two convention columns cannot land on the same field
// ---------------------------------------------------------------------------

it('refuses two convention columns landing on the same field', function (): void {
    auraTable([
        Column::convention('status', 'badge'),
        Column::convention('status', 'badge'),
    ])->definition();
})->throws(InvalidDefinition::class, 'Two columns share the key');
