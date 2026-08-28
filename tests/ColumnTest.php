<?php

declare(strict_types=1);

use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\Inference;
use TamasLabs\Aura\Table\Presets\Money;
use TamasLabs\Aura\Table\Presets\Options;
use TamasLabs\Aura\Table\Presets\Timestamp;
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

    expect($cell['elements'])->toBe(['active' => 'Aktív', 'suspended' => 'Felfüggesztett']);

    assertMatchesAuraHeader([$cell]);
});

it('infers through a relation one level deep', function (): void {
    $cell = cell(Column::make('company.tier')->filterable());

    expect($cell['elements'])->toBe(['free_trial' => 'Free Trial', 'paid' => 'Paid']);
});

it('infers nothing through a relation it cannot follow', function (): void {
    $cell = cell(Column::make('company.owner.tier'));

    expect($cell)->not->toHaveKey('elements');
});

it('names enum cases itself when the enum does not', function (): void {
    expect(Inference::elementsFrom(Tier::class))->toBe(['free_trial' => 'Free Trial', 'paid' => 'Paid']);
});

it('takes the labels from the enum when it offers them', function (): void {
    expect(Inference::elementsFrom(Status::class))->toBe(['active' => 'Aktív', 'suspended' => 'Felfüggesztett']);
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

    expect($cell['elements'])->toBe(['free_trial' => 'Free Trial', 'paid' => 'Paid']);
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
        ->and($cell['elements'])->toBe(['free_trial' => 'Free Trial', 'paid' => 'Paid']);
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
