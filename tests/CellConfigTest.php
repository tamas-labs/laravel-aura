<?php

declare(strict_types=1);

use TamasLabs\Aura\Cell\Badge;
use TamasLabs\Aura\Cell\Button;
use TamasLabs\Aura\Cell\CellConfig;
use TamasLabs\Aura\Cell\Condition;
use TamasLabs\Aura\Cell\Custom;
use TamasLabs\Aura\Cell\Icon;
use TamasLabs\Aura\Cell\Link;
use TamasLabs\Aura\Cell\Modal;
use TamasLabs\Aura\Cell\Progress;
use TamasLabs\Aura\Cell\Reference;
use TamasLabs\Aura\Cell\Text;
use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Tests\Fixtures\Status;
use TamasLabs\Aura\Tests\Fixtures\Tier;

it('builds a config Aura accepts, for every type', function (string $type, CellConfig $config): void {
    $resolved = $config->resolve('status');

    expect($resolved['type'])->toBe($type);

    assertMatchesAuraConfig($resolved, 'status');
})->with([
    'static' => ['static', fn (): CellConfig => Text::make('—')],
    'reference' => ['reference', fn (): CellConfig => Reference::make()],
    'badge' => ['badge', fn (): CellConfig => Badge::make()->variant('success')],
    'link' => ['link', fn (): CellConfig => Link::make()->route('users.{id}.show')],
    'button' => ['button', fn (): CellConfig => Button::make('Edit')->route('users.{id}.edit')],
    'icon' => ['icon', fn (): CellConfig => Icon::make('pencil')],
    'modal' => ['modal', fn (): CellConfig => Modal::destroy()->icon('trash')],
    'progress' => ['progress', fn (): CellConfig => Progress::make()->max(100)],
    'custom' => ['custom', fn (): CellConfig => Custom::template('{first_name} ({status})')],
]);

it('refuses a config that would render an empty cell', function (CellConfig $config): void {
    $config->resolve('status');
})->with([
    'static without text' => [fn (): CellConfig => Text::make()],
    'icon without a glyph' => [fn (): CellConfig => Icon::make()->size('lg')],
    'modal without a trigger' => [fn (): CellConfig => Modal::destroy()],
])->throws(InvalidDefinition::class, 'renders an empty cell');

it('cannot leave a row-reading config empty in the first place', function (): void {
    // The three above are the only types that can be built incomplete: every
    // other one either takes its content in the factory or reads the column's
    // field, which is filled in by then.
    expect(Badge::make()->resolve('status'))->toHaveKey('field')
        ->and(Link::make()->resolve('email'))->toHaveKey('field')
        ->and(Progress::make()->resolve('done'))->toHaveKey('field');
});

it('reads the column own field unless told otherwise', function (): void {
    expect(Reference::make()->resolve('balance')['field'])->toBe('balance')
        ->and(Reference::make('other')->resolve('balance')['field'])->toBe('other');
});

it('adds no single field to a config that reads several', function (): void {
    $resolved = Reference::combined(['first_name', 'last_name'])->resolve('full_name');

    expect($resolved)->not->toHaveKey('field')
        ->and($resolved['fields'])->toBe(['first_name', 'last_name']);
});

it('inherits the column formatting, because Aura stops applying it', function (): void {
    // The renderer is handed the config alone (TableBodyRow → renderSegmentNode),
    // so without this the currency column would suddenly show raw figures.
    $header = ['field' => 'balance', 'key' => 'balance', 'currency' => true, 'align' => 'end'];

    $resolved = Reference::make()->resolve('balance', $header);

    expect($resolved['currency'])->toBeTrue()
        ->and($resolved['align'])->toBe('end');
});

it('carries datetime through, though the config schema omits it', function (): void {
    // `datetime` is absent from the column-config schemas but read by
    // buildFormatConfig.ts all the same, and the configs allow extra keys.
    $resolved = Reference::make()->resolve('created_at', ['datetime' => true, 'field' => 'created_at']);

    expect($resolved['datetime'])->toBeTrue();

    assertMatchesAuraConfig($resolved, 'created_at');
});

it('lets the config override what the column formatted', function (): void {
    $resolved = Reference::make()->currency(false)->resolve('balance', ['currency' => true]);

    expect($resolved['currency'])->toBeFalse();
});

it('leaves a type that formats nothing alone', function (): void {
    $resolved = Progress::make()->resolve('balance', ['currency' => true, 'align' => 'end']);

    expect($resolved)->not->toHaveKey('currency')
        ->and($resolved)->not->toHaveKey('align');
});

it('builds a badge per enum case, with the colours the enum offers', function (): void {
    $resolved = Badge::fromEnum(Status::class)->resolve('status');

    expect($resolved['mapping'])->toBe([
        'active' => ['label' => 'Aktív', 'variant' => 'success', 'icon' => 'check'],
        'suspended' => ['label' => 'Felfüggesztett', 'variant' => 'danger', 'icon' => 'ban'],
    ]);

    assertMatchesAuraConfig($resolved, 'status');
});

it('still builds badges from an enum that implements nothing', function (): void {
    $resolved = Badge::fromEnum(Tier::class)->resolve('tier');

    expect($resolved['mapping'])->toBe([
        'free_trial' => ['label' => 'Free Trial'],
        'paid' => ['label' => 'Paid'],
    ]);
});

it('emits the key an icon mapping needs to select on', function (): void {
    // Aura looks a mapping up under `field ?? key`, and an icon config has no
    // `field` — without the key the mapping would never match anything.
    $resolved = Icon::make()->mapping(['active' => ['icon' => 'check']])->resolve('status');

    expect($resolved['key'])->toBe('status');
});

it('leaves the key off a config that has a field to select on', function (): void {
    $resolved = Badge::make()->mapping(['active' => ['variant' => 'success']])->resolve('status');

    expect($resolved)->not->toHaveKey('key')
        ->and($resolved['field'])->toBe('status');
});

it('emits the key an icon needs before Aura will make it a link', function (): void {
    // renderIconNode wraps the glyph in an <a> only when `route` AND `key` are
    // both present; link, button and modal go through action-node-helpers and
    // need the route alone. Without the key the icon renders and navigates
    // nowhere, with nothing said about it anywhere.
    $resolved = Icon::make('pencil')->route('users.{id}.edit')->resolve('edit_icon');

    expect($resolved['key'])->toBe('id');

    assertMatchesAuraConfig($resolved, 'edit_icon');
});

it('names the icon key after the column when the route has no placeholder', function (): void {
    // What Aura's own preprocessor does for `create`, which needs no id: it
    // attaches the cell key regardless, purely so the anchor gets built.
    $resolved = Icon::make('plus')->route('users.create')->resolve('create_icon', ['key' => 'id']);

    expect($resolved['key'])->toBe('id');
});

it('lets a mapping keep the key it selects on, route or no route', function (): void {
    // The value of `key` is read only by the mapping; the link just needs one.
    $resolved = Icon::make()
        ->mapping(['active' => ['icon' => 'check']])
        ->route('users.{id}.edit')
        ->resolve('status');

    expect($resolved['key'])->toBe('status');
});

it('carries the route key into a branch, because the root key is stripped', function (): void {
    // stripLogicProps removes the root `key` — there it selects the condition
    // field — so a per-row condition over a linking icon would hide the cell
    // correctly and then render the allowed rows without their link.
    $config = Icon::make('pencil')->route('users.{id}.edit')->on('can_edit')
        ->when(Condition::isTrue(), fn (Icon $i): Icon => $i);

    $resolved = $config->resolve('edit_icon');

    expect($resolved['key'])->toBe('can_edit')
        ->and(auraDigArray($resolved, 'if', 0))->toBe(['true' => true, 'key' => 'id'])
        ->and($config->resolve('edit_icon'))->toBe($resolved);

    assertMatchesAuraConfig($resolved, 'edit_icon');
});

it('keys the else branch too, which renders just as much', function (): void {
    $resolved = Icon::make('pencil')->route('users.{id}.edit')->on('can_edit')
        ->when(Condition::isTrue(), fn (Icon $i): Icon => $i)
        ->otherwise(fn (Icon $i): Icon => $i->color('muted'))
        ->resolve('edit_icon');

    expect(auraDigArray($resolved, 'else'))->toBe(['color' => 'muted', 'key' => 'id']);
});

it('keys a branch that carries the route itself', function (): void {
    // The decision is per branch, against the settings that branch resolves
    // with: the base here has no route at all.
    $resolved = Icon::make('pencil')->on('status')
        ->when(Condition::eq('draft'), fn (Icon $i): Icon => $i->route('posts.{id}.edit'))
        ->resolve('edit_icon');

    expect(auraDigArray($resolved, 'if', 0))
        ->toBe(['eq' => 'draft', 'route' => 'posts.{id}.edit', 'key' => 'id']);
});

it('leaves a branch key the caller named alone', function (): void {
    $resolved = Icon::make('pencil')->route('users.{id}.edit')->on('can_edit')
        ->when(Condition::isTrue(), fn (Icon $i): Icon => $i->on('uuid'))
        ->resolve('edit_icon');

    expect(auraDigArray($resolved, 'if', 0))->toBe(['true' => true, 'key' => 'uuid']);
});

it('leaves a nested selector alone and keys the leaf below it', function (): void {
    // A branch with conditions of its own is not a leaf: its `key` is stripped
    // in turn, one level further down.
    $resolved = Icon::make('pencil')->route('users.{id}.edit')->on('can_edit')
        ->when(Condition::isTrue(), fn (Icon $i): Icon => $i->on('tier')
            ->when(Condition::eq('gold'), fn (Icon $x): Icon => $x->color('warning')))
        ->resolve('edit_icon');

    $branch = auraDigArray($resolved, 'if', 0);

    expect($branch['key'])->toBe('tier')
        ->and(auraDigArray($branch, 'if', 0))
        ->toBe(['eq' => 'gold', 'color' => 'warning', 'key' => 'id']);
});

it('nests a trigger of any type inside a modal', function (): void {
    $resolved = Modal::destroy()
        ->route('users.{id}.destroy')
        ->content(Button::make('Delete')->variant('danger'))
        ->resolve('id');

    expect($resolved['id'])->toBe('destroyModal')
        ->and(auraDigArray($resolved, 'content')['type'])->toBe('button');

    assertMatchesAuraConfig($resolved, 'id');
});

it('builds a stacked progress bar from its segments', function (): void {
    $resolved = Progress::stacked([
        ['field' => 'done', 'variant' => 'success'],
        ['field' => 'failed', 'variant' => 'danger'],
    ])->resolve('progress');

    expect($resolved['stacked'])->toBeTrue()
        ->and($resolved)->not->toHaveKey('field');

    assertMatchesAuraConfig($resolved, 'progress');
});

it('sets a safe rel when a link opens a new tab', function (): void {
    expect(Link::make()->target('_blank')->resolve('email')['rel'])->toBe('noopener noreferrer');
});

it('sets any contract key the builder has no method for', function (): void {
    $resolved = Text::make('x')->merge(['data-testid' => 'note', 'fontWeight' => 700])->resolve('note');

    expect($resolved['data-testid'])->toBe('note')
        ->and($resolved['fontWeight'])->toBe(700);

    assertMatchesAuraConfig($resolved, 'note');
});

it('refuses to set a structural key through the escape hatch', function (string $key): void {
    Text::make('x')->merge([$key => 'anything']);
})->with(['type', 'key', 'if', 'else'])->throws(InvalidDefinition::class, 'decides how the rest');

it('refuses a structural key set directly, not only through the escape hatch', function (string $key): void {
    // merge() delegates to set(), and set() is public in its own right — so the
    // guard belongs there. A hand-written `key` would otherwise win over the one
    // the conditions are emitted with: resolve() builds `type + settings +
    // conditionals`, and the settings come first.
    Text::make('x')->set($key, 'anything');
})->with(['type', 'key', 'if', 'else'])->throws(InvalidDefinition::class, 'decides how the rest');

it('sets the formatter keys the config schemas omit but Aura reads', function (): void {
    $resolved = Reference::make()->datetime()->time(false)->raw()->resolve('created_at');

    expect($resolved['datetime'])->toBeTrue()
        ->and($resolved['time'])->toBeFalse()
        ->and($resolved['raw'])->toBeTrue();

    assertMatchesAuraConfig($resolved, 'created_at');
});

it('carries a nested trigger into a branch, which is not emitted through resolve', function (): void {
    // A branch is built from settings() alone and never goes through resolve(),
    // so anything a type computes there has to be computed for the branches too.
    // Without that, this whole `when()` emits the condition and nothing else:
    // the branch matches, changes nothing, and the modal keeps its base trigger.
    $resolved = Modal::make('confirm')
        ->icon('trash')
        ->when(Condition::eq('archived'), fn (Modal $m): Modal => $m->content(Text::make('Restore')))
        ->resolve('status');

    $branch = auraDigArray($resolved, 'if', 0);

    expect(auraDigArray($branch, 'content'))->toBe(['type' => 'static', 'value' => 'Restore']);

    assertMatchesAuraConfig($resolved, 'status');
});

it('resolves without changing the builder it was called on', function (): void {
    // resolve() works on a copy so the same builder can serve a rebuild — from
    // the cache, or from a second call — and answer the same thing twice.
    $modal = Modal::make('confirm')
        ->when(Condition::notNull(), fn (Modal $m): Modal => $m->content(Text::make('go')));

    expect($modal->resolve('id'))->toBe($modal->resolve('id'));
});

it('refuses an absolute route, which Aura would mangle into a path', function (): void {
    // resolve-route.ts turns every dot into a slash: https://app.test/users/5
    // would render as /https://app/test/users/5.
    Link::make()->route('https://app.test/users/{id}');
})->throws(InvalidDefinition::class, 'is absolute');

it('refuses a placeholder Aura would not substitute', function (): void {
    Button::make('Edit')->route('users.{user id}.edit');
})->throws(InvalidDefinition::class, 'will not substitute');

it('accepts the two route spellings Aura resolves', function (string $route): void {
    expect(Icon::make('pencil')->route($route)->resolve('id')['route'])->toBe($route);
})->with(['users.{id}.edit', '/users/{id}/edit']);

it('accepts a macro on a cell builder', function (): void {
    Badge::macro('successful', fn (): Badge => $this->variant('success')->pill());

    /** @phpstan-ignore method.notFound */
    $badge = Badge::make()->successful();

    $resolved = $badge instanceof Badge ? $badge->resolve('status') : [];

    expect($resolved['variant'])->toBe('success')
        ->and($resolved['pill'])->toBeTrue();
});
