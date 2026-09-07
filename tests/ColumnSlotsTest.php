<?php

declare(strict_types=1);

use TamasLabs\Aura\Cell\Concerns\HasElement;
use TamasLabs\Aura\Cell\Concerns\HasFormatting;
use TamasLabs\Aura\Cell\Concerns\HasTypography;
use TamasLabs\Aura\Cell\Text;
use TamasLabs\Aura\Table\Column;
use TamasLabs\AuraSchema\AuraSchema;

/**
 * The setters `Column` and the cell configurations both offer.
 *
 * Each row is the name, the two calls, and the trait the cell-config half lives
 * in. The two closures are the duplication this suite exists to hold together:
 * they have to emit the same contract slot, because a column with no
 * `columnConfigs` entry *is* the configuration its data cells are rendered with
 * — Aura's `TableBodyRow` passes the header cell itself as the cell config.
 *
 * @return array<string, array{Closure(Column): Column, Closure(Text): Text, class-string}>
 */
function auraSharedSetters(): array
{
    return [
        'number' => [fn (Column $c): Column => $c->number(), fn (Text $t): Text => $t->number(), HasFormatting::class],
        'currency' => [fn (Column $c): Column => $c->currency(), fn (Text $t): Text => $t->currency(), HasFormatting::class],
        'date' => [fn (Column $c): Column => $c->date(), fn (Text $t): Text => $t->date(), HasFormatting::class],
        'datetime' => [fn (Column $c): Column => $c->datetime(), fn (Text $t): Text => $t->datetime(), HasFormatting::class],
        'time' => [fn (Column $c): Column => $c->time(), fn (Text $t): Text => $t->time(), HasFormatting::class],
        'phone' => [fn (Column $c): Column => $c->phone(), fn (Text $t): Text => $t->phone(), HasFormatting::class],
        'raw' => [fn (Column $c): Column => $c->raw(), fn (Text $t): Text => $t->raw(), HasFormatting::class],
        'slice' => [fn (Column $c): Column => $c->slice(7), fn (Text $t): Text => $t->slice(7), HasFormatting::class],
        'uppercase' => [fn (Column $c): Column => $c->uppercase(), fn (Text $t): Text => $t->uppercase(), HasFormatting::class],
        'lowercase' => [fn (Column $c): Column => $c->lowercase(), fn (Text $t): Text => $t->lowercase(), HasFormatting::class],
        'capitalize' => [fn (Column $c): Column => $c->capitalize(), fn (Text $t): Text => $t->capitalize(), HasFormatting::class],
        'monospace' => [fn (Column $c): Column => $c->monospace(), fn (Text $t): Text => $t->monospace(), HasFormatting::class],
        'align' => [fn (Column $c): Column => $c->align('end'), fn (Text $t): Text => $t->align('end'), HasTypography::class],
        'class' => [fn (Column $c): Column => $c->class('c'), fn (Text $t): Text => $t->class('c'), HasElement::class],
        'style' => [fn (Column $c): Column => $c->style('color:red'), fn (Text $t): Text => $t->style('color:red'), HasElement::class],
    ];
}

/**
 * The same table reduced to what a reflection comparison needs: the trait the
 * cell-config half lives in, and the shared method name.
 *
 * @return array<string, array{class-string, string}>
 */
function auraSharedSetterTraits(): array
{
    $rows = [];

    foreach (auraSharedSetters() as $name => $row) {
        $rows[$name] = [$row[2], $name];
    }

    return $rows;
}

it('emits the same contract slot from a column setter and its cell-config twin', function (Closure $onColumn, Closure $onConfig): void {
    // The two are written out separately on purpose — see the note on `Column`
    // — so nothing but this test stops them drifting into two spellings of one
    // contract slot. `additionalProperties: true` on both documents means a
    // drifted name would validate, travel, and be dropped by the browser in
    // silence.
    /** @var Column $column */
    $column = $onColumn(Column::make('salary'));

    /** @var Text $config */
    $config = $onConfig(Text::make('x'));

    $columnSlots = array_diff_key($column->resolve(null), Column::make('salary')->resolve(null));
    $configSlots = array_diff_key($config->resolve('salary'), Text::make('x')->resolve('salary'));

    expect($columnSlots)->toBe($configSlots);
})->with(auraSharedSetters());

it('accepts the same arguments from a column setter as from its cell-config twin', function (string $trait, string $name): void {
    // Emitting the same key is half of it: a `slice(int)` here and a
    // `slice(int, string)` there would be one name with two meanings.
    $signature = static fn (ReflectionMethod $method): array => array_map(
        static fn (ReflectionParameter $parameter): string => sprintf(
            '%s $%s%s',
            (string) $parameter->getType(),
            $parameter->getName(),
            $parameter->isDefaultValueAvailable()
                ? ' = '.var_export($parameter->getDefaultValue(), true)
                : '',
        ),
        $method->getParameters(),
    );

    expect($signature(new ReflectionMethod(Column::class, $name)))
        ->toBe($signature(new ReflectionMethod($trait, $name)));
})->with(auraSharedSetterTraits());

it('names only header-cell slots the contract declares', function (): void {
    // `headerCell` sets `additionalProperties: true` — Aura drops what it does
    // not know, without a word — so schema validation can never catch a slot
    // this package invented. Only a list read out of the schema can.
    //
    // Scoped to `Column`, which is where the header cell's surface is declared.
    // `Inference` and the presets write through `Column::default()`.
    $declared = array_keys(auraDigArray(AuraSchema::get('header'), '$defs', 'headerCell', 'properties'));

    preg_match_all(
        "/(?:->(?:set|default)\('|attributes\['|inferred\[')([a-zA-Z-]+)'/",
        auraPackageFile('src/Table/Column.php'),
        $matches,
    );

    $emitted = array_values(array_unique($matches[1]));

    // Guard the guard: a scan that matches nothing would pass for the wrong
    // reason for as long as it took someone to notice.
    expect(count($emitted))->toBeGreaterThan(20)
        ->and(array_diff($emitted, $declared))->toBe([]);
});
