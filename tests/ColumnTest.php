<?php

declare(strict_types=1);

use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\Inference;
use TamasLabs\Aura\Table\Presets\Money;
use TamasLabs\Aura\Table\Presets\Options;
use TamasLabs\Aura\Table\Presets\Timestamp;
use TamasLabs\Aura\Tests\Fixtures\Flag;
use TamasLabs\Aura\Tests\Fixtures\LegacyUser;
use TamasLabs\Aura\Tests\Fixtures\Status;
use TamasLabs\Aura\Tests\Fixtures\Tier;
use TamasLabs\Aura\Tests\Fixtures\TypedUser;

/**
 * Resolve a column against the casted fixture model.
 *
 * @return array<string, mixed>
 */
function cell(Column $column): array
{
    return $column->resolve(new TypedUser);
}

it('builds a header cell Aura accepts from a single call', function (): void {
    $cell = cell(Column::make('last_name'));

    expect($cell)->toBe(['content' => 'Last Name', 'field' => 'last_name', 'key' => 'last_name']);

    assertMatchesAuraHeader([$cell]);
});

it('titles the heading from a dotted field', function (): void {
    expect(cell(Column::make('company.name'))['content'])->toBe('Company Name');
});

it('keeps an explicit heading over the derived one', function (): void {
    expect(cell(Column::make('last_name', 'Vezetéknév'))['content'])->toBe('Vezetéknév');
});

it('defaults the key to the field', function (): void {
    expect(cell(Column::make('company.name'))['key'])->toBe('company.name');
});

it('gives the selection column the model key', function (): void {
    $cell = cell(Column::selection());

    expect($cell['field'])->toBe('id')
        ->and($cell['key'])->toBe('id')
        ->and($cell['selectable'])->toBeTrue()
        ->and($cell['content'])->toBeNull();

    assertMatchesAuraHeader([$cell]);
});

it('infers currency and alignment from a decimal cast', function (): void {
    $cell = cell(Column::make('balance'));

    expect($cell['currency'])->toBeTrue()
        ->and($cell['align'])->toBe('end');
});

it('infers a range search from a datetime cast', function (): void {
    $cell = cell(Column::make('created_at')->searchable());

    expect($cell['datetime'])->toBeTrue()
        ->and($cell['between'])->toBeTrue();
});

it('does not offer a range on a column with no search input', function (): void {
    $cell = cell(Column::make('created_at'));

    expect($cell['datetime'])->toBeTrue()
        ->and($cell)->not->toHaveKey('between');
});

it('infers the filter options from an enum cast', function (): void {
    $cell = cell(Column::make('status')->filterable());

    expect($cell['elements'])->toEqual((object) ['active' => 'Aktív', 'suspended' => 'Felfüggesztett']);

    assertMatchesAuraHeader([$cell]);
});

it('emits filter options keyed 0/1 as an object, not as a list of labels', function (): void {
    // `elements` takes both shapes the contract allows — a list of values, or a
    // map of value → label — and PHP cannot tell them apart when the keys run
    // 0…n-1. As a list the filter would ask the server for the *label*: a WHERE
    // on 'Nem' against an integer column, which is silently no rows rather than
    // an error.
    $cell = cell(Column::make('flag')->filterable()->options(Flag::class));

    expect(json_encode($cell['elements']))->toBe('{"0":"Nem","1":"Igen"}');
});

it('keeps a list of options a list', function (): void {
    // The other half of the same ambiguity: a list means the value *is* the
    // label, and turning that one into an object would send 0 and 1 as the
    // filter values.
    $cell = cell(Column::make('flag')->filterable()->elements(['Nem', 'Igen']));

    expect(json_encode($cell['elements']))->toBe('["Nem","Igen"]');

    assertMatchesAuraHeader([$cell]);
});

it('says which shape was meant when the keys run 0, 1, 2', function (): void {
    $cell = cell(Column::make('flag')->filterable()->elementsMap([0 => 'Nem', 1 => 'Igen']));

    expect(json_encode($cell['elements']))->toBe('{"0":"Nem","1":"Igen"}');
});

it('infers through a relation one level deep', function (): void {
    $cell = cell(Column::make('company.tier')->filterable());

    expect($cell['elements'])->toEqual((object) ['free_trial' => 'Free Trial', 'paid' => 'Paid']);
});

it('infers nothing through a relation it cannot follow', function (): void {
    $cell = cell(Column::make('company.owner.tier'));

    expect($cell)->not->toHaveKey('elements');
});

it('names enum cases itself when the enum does not', function (): void {
    expect(Inference::elementsFrom(Tier::class))->toEqual((object) ['free_trial' => 'Free Trial', 'paid' => 'Paid']);
});

it('takes the labels from the enum when it offers them', function (): void {
    expect(Inference::elementsFrom(Status::class))->toEqual((object) ['active' => 'Aktív', 'suspended' => 'Felfüggesztett']);
});

it('lets an explicit setting win over the inferred one', function (): void {
    $cell = cell(Column::make('balance')->align('center')->currency(false));

    expect($cell['align'])->toBe('center')
        ->and($cell['currency'])->toBeFalse();
});

it('infers nothing at all when inference is turned off', function (): void {
    $cell = cell(Column::make('balance')->withoutInference());

    expect($cell)->toBe(['content' => 'Balance', 'field' => 'balance', 'key' => 'balance']);
});

it('offers enum options on a column the model does not cast', function (): void {
    $cell = cell(Column::make('plan')->filterable()->options(Tier::class));

    expect($cell['elements'])->toEqual((object) ['free_trial' => 'Free Trial', 'paid' => 'Paid']);
});

it('applies a preset', function (): void {
    $cell = cell(Column::make('minor_units', 'Total')->apply(new Money));

    expect($cell['currency'])->toBeTrue()
        ->and($cell['align'])->toBe('end')
        ->and($cell['monospace'])->toBeTrue();
});

it('lets an explicit call beat a preset, in either order', function (string $order): void {
    $column = $order === 'before'
        ? Column::make('minor_units')->align('center')->apply(new Money)
        : Column::make('minor_units')->apply(new Money)->align('center');

    expect(cell($column)['align'])->toBe('center');
})->with(['before', 'after']);

it('offers a range search through the timestamp preset', function (): void {
    $cell = cell(Column::make('archived_at')->searchable()->apply(new Timestamp));

    expect($cell['datetime'])->toBeTrue()
        ->and($cell['between'])->toBeTrue();
});

it('builds a date-only column through the timestamp preset', function (): void {
    $cell = cell(Column::make('born_on')->apply(Timestamp::date()));

    expect($cell['date'])->toBeTrue()
        ->and($cell)->not->toHaveKey('datetime');
});

it('builds a filter from an enum through the options preset', function (): void {
    $cell = cell(Column::make('plan')->apply(new Options(Tier::class)));

    expect($cell['filterable'])->toBeTrue()
        ->and($cell['align'])->toBe('center')
        ->and($cell['elements'])->toEqual((object) ['free_trial' => 'Free Trial', 'paid' => 'Paid']);
});

it('accepts a macro on the builder', function (): void {
    Column::macro('money', fn (): Column => $this->apply(new Money));

    // The call the macro just created; static analysis cannot see a method that
    // was registered a line ago.
    /** @phpstan-ignore method.notFound, argument.type */
    $cell = cell(Column::make('balance')->money());

    expect($cell['monospace'])->toBeTrue();
});

it('sets any contract key the builder has no method for', function (): void {
    $cell = cell(Column::make('note')->set('data-testid', 'note-column')->merge(['fontWeight' => 700]));

    expect($cell['data-testid'])->toBe('note-column')
        ->and($cell['fontWeight'])->toBe(700);

    assertMatchesAuraHeader([$cell]);
});

it('refuses a heading that spans nothing', function (): void {
    cell(Column::heading('Account', colspan: 1));
})->throws(InvalidDefinition::class, 'grouping cell');

it('builds a grouping heading that spans several columns', function (): void {
    $cells = [cell(Column::heading('Account', colspan: 3)), cell(Column::make('id'))];

    expect($cells[0])->toBe(['content' => 'Account', 'colspan' => 3]);

    assertMatchesAuraHeader($cells);
});

it('refuses a heading that is an empty string rather than no heading', function (): void {
    // The contract types content as a non-empty string or null, and Aura's own
    // response validation rejects the whole table over the one cell.
    cell(Column::make('edit', ''));
})->throws(InvalidDefinition::class, 'empty string');

it('refuses a cell that names both a field and a fields list', function (): void {
    // The fourth structural rule of the header schema, and the only one that was
    // not checked: `not: {required: [field, fields]}`. A cell carrying both
    // fails Aura's own response validation, which takes down the whole table
    // rather than the one column.
    Column::make('last_name')->set('fields', ['first_name', 'last_name'])->resolve(null);
})->throws(InvalidDefinition::class, 'never from both');

it('refuses a selection column that was also given several fields', function (): void {
    // Reached through inference rather than the escape hatch: a selection column
    // has its `field` filled in from the model key, so `fields` beside it lands
    // in the same invalid cell.
    Column::selection()->set('fields', ['a', 'b'])->resolve(new TypedUser);
})->throws(InvalidDefinition::class, 'never from both');

it('infers nothing through a method that is not a relation, without running it', function (): void {
    // A dotted field names a method, and `method_exists()` was the only guard:
    // `Column::make('delete.x')` used to call `$model->delete()` during the
    // header build. Nothing here may run on the way to a default.
    LegacyUser::$called = false;

    $cell = Column::make('fullName.x')->resolve(new LegacyUser);

    expect($cell)->toBe(['content' => 'Fullname X', 'field' => 'fullName.x', 'key' => 'fullName.x'])
        ->and(LegacyUser::$called)->toBeFalse();
});

it('still infers through an untyped relation method', function (): void {
    // The other half of the same guard: a relation carrying only a `@return`
    // docblock has to keep working, or the fix would cost more than the hazard.
    $cell = Column::make('company.name')->resolve(new LegacyUser);

    expect($cell['field'])->toBe('company.name');
});
