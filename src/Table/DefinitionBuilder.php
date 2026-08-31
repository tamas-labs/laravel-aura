<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use Illuminate\Database\Eloquent\Model;
use TamasLabs\Aura\Cell\CellRules;
use TamasLabs\Aura\Exceptions\InvalidDefinition;

/**
 * Turns a list of columns into the request-independent half of the response.
 *
 * One pass produces all three parts of a {@see TableBlueprint} — the blocks the
 * browser renders, the field whitelist the query layer enforces, and the fields
 * the response has to hand over as numbers — because they are three readings of
 * one definition. Building them separately is how a header comes to advertise a
 * sort the server then refuses.
 *
 * The caller guarantees at least one entry: an empty column list is a fact
 * about the *table*, and {@see AuraTable} reports it with the table's own name.
 *
 * @internal
 */
final readonly class DefinitionBuilder
{
    /**
     * @param  list<Column|ColumnGroup>  $entries  The columns, left to right.
     * @param  Model  $model  The model column inference reads.
     * @param  string|null  $resource  The route base an escalated action builds on.
     */
    public function __construct(
        private array $entries,
        private Model $model,
        private TableSettings $settings,
        private ?Footer $footer = null,
        private ?CellRules $rowRules = null,
        private ?string $resource = null,
    ) {}

    /**
     * @throws InvalidDefinition
     */
    public function build(): TableBlueprint
    {
        if ($this->resource !== null) {
            Action::assertUsableResource($this->resource);
        }

        $grouped = $this->isGrouped();

        /** @var list<array<string, mixed>> $top */
        $top = [];
        /** @var list<array<string, mixed>> $second */
        $second = [];
        /** @var list<ResolvedColumn> $columns */
        $columns = [];

        foreach ($this->entries as $entry) {
            if ($entry instanceof ColumnGroup) {
                $top[] = $entry->resolve();

                foreach ($entry->columns() as $column) {
                    $resolved = new ResolvedColumn($column, $column->resolve($this->model));

                    $second[] = $resolved->cell;
                    $columns[] = $resolved;
                }

                continue;
            }

            $resolved = new ResolvedColumn($entry, $entry->resolve($this->model));

            if ($grouped) {
                // Every data column has to live in the *last* header row; an
                // ungrouped one gets an empty cell above it rather than a
                // rowspan. See {@see self::spacer()}.
                $top[] = self::spacer($resolved->cell);
                $second[] = $resolved->cell;
            } else {
                $top[] = $resolved->cell;
            }

            $columns[] = $resolved;
        }

        self::assertKeysAreUnique($columns);
        self::assertActionsAreWellFormed($columns);

        $cells = CellConfigs::from($columns, $this->resource);
        $permissions = ColumnPermissions::from($columns);

        return new TableBlueprint(
            definition: $this->definition(
                rows: $grouped ? [$top, $second] : [$top],
                columns: $columns,
                configs: $cells->configs(),
                // The header publishes the whitelist's own list rather than a
                // second one built the same way: `searchableItems` and what the
                // query layer accepts are the same array, structurally.
                searchableItems: $permissions->globalSearch,
            ),
            permissions: $permissions,
            numericFields: $cells->numericFields(),
        );
    }

    /**
     * `header`, and `body` / `footer` when they carry anything.
     *
     * @param  list<list<array<string, mixed>>>  $rows
     * @param  list<ResolvedColumn>  $columns
     * @param  array<string, array<string, mixed>>  $configs
     * @param  list<string>  $searchableItems
     * @return array<string, mixed>
     */
    private function definition(array $rows, array $columns, array $configs, array $searchableItems): array
    {
        $header = ['rows' => array_map(static fn (array $cells): array => ['cells' => $cells], $rows)];

        $headerSettings = $this->settings->headerSettings();

        if ($searchableItems !== []) {
            $headerSettings['searchableItems'] = $searchableItems;
        }

        if ($headerSettings !== []) {
            $header['settings'] = $headerSettings;
        }

        $definition = ['header' => $header];

        $body = $this->body($columns, $configs);

        if ($body !== []) {
            $definition['body'] = $body;
        }

        $footer = $this->footerBlock();

        if ($footer !== null) {
            $definition['footer'] = $footer;
        }

        return $definition;
    }

    /**
     * @param  list<ResolvedColumn>  $columns
     * @param  array<string, array<string, mixed>>  $configs
     * @return array<string, mixed>
     */
    private function body(array $columns, array $configs): array
    {
        $body = [];

        $bodySettings = $this->settings->bodySettings();

        if ($bodySettings !== []) {
            $body['settings'] = $bodySettings;
        }

        if ($configs !== []) {
            $body['columnConfigs'] = $configs;
        }

        $styles = [];

        foreach ($columns as $resolved) {
            $class = $resolved->column->resolvedCellClass();
            $key = $resolved->key();

            if ($class !== null && $key !== null) {
                $styles[$key] = $class;
            }
        }

        if ($styles !== []) {
            $body['columnStyles'] = $styles;
        }

        if ($this->rowRules instanceof CellRules) {
            // No column to borrow a field from, so the rules have to name their
            // own with ->on(); the builder says so if they do not.
            $body['rowRules'] = $this->rowRules->resolve('');
        }

        return $body;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function footerBlock(): ?array
    {
        if (! $this->footer instanceof Footer) {
            return null;
        }

        $rows = [];

        foreach ($this->footer->rows() as $cells) {
            $rows[] = ['cells' => array_map(
                fn (Column $column): array => $column->resolve($this->model),
                $cells,
            )];
        }

        if ($rows === []) {
            return null;
        }

        $block = ['rows' => $rows];

        $footerSettings = $this->settings->footerSettings();

        if ($footerSettings !== []) {
            $block['settings'] = $footerSettings;
        }

        return $block;
    }

    /**
     * The empty cell that sits above an ungrouped column in a grouped header.
     *
     * Aura reads the data columns from the **last** header row only
     * (`TableBody.tsx`, "Determine columns from the last row of the header"), so
     * a column parked in the first row with `rowspan: 2` would get a heading and
     * no data at all — the body would silently render one `<td>` fewer than the
     * header has `<th>`s. Every column therefore appears in the last row, and
     * the first row carries a placeholder to keep the widths lined up.
     *
     * The placeholder repeats the column's identity because the contract has no
     * other way to spell a one-column-wide cell: a cell with no field is a
     * grouping cell, and grouping cells have to span at least two columns. It
     * carries no behaviour, so nothing renders twice.
     *
     * @param  array<string, mixed>  $cell
     * @return array<string, mixed>
     */
    private static function spacer(array $cell): array
    {
        $spacer = ['content' => null];

        foreach (['field', 'fields', 'key', 'width', 'show'] as $carried) {
            if (array_key_exists($carried, $cell)) {
                $spacer[$carried] = $cell[$carried];
            }
        }

        return $spacer;
    }

    private function isGrouped(): bool
    {
        foreach ($this->entries as $entry) {
            if ($entry instanceof ColumnGroup) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<ResolvedColumn>  $columns
     *
     * @throws InvalidDefinition
     */
    private static function assertKeysAreUnique(array $columns): void
    {
        /** @var array<string, ResolvedColumn> $seen */
        $seen = [];

        foreach ($columns as $resolved) {
            $key = $resolved->key();

            if ($key === null) {
                continue;
            }

            $previous = $seen[$key] ?? null;

            if ($previous instanceof ResolvedColumn) {
                // An action column's key is the route placeholder rather than a
                // name it picked, so "give one of them an explicit key()" is
                // advice that only holds for one of the two. Say which.
                $action = $resolved->isActionColumn() ? $resolved : $previous;
                $other = $resolved->isActionColumn() ? $previous : $resolved;

                throw $action->isActionColumn()
                    ? InvalidDefinition::actionKeyTaken($key, $other->flag('selectable'))
                    : InvalidDefinition::duplicateKey($key);
            }

            $seen[$key] = $resolved;
        }
    }

    /**
     * Aura's four resource actions, and where they are allowed to appear.
     *
     * The browser generates a configuration for a field named `edit_icon` and
     * friends, keying it — like every other entry in `columnConfigs` — by the
     * field name alone. Two consequences, and neither is visible in the
     * payload:
     *
     * - The route is built from *whichever* cell the generator reaches first,
     *   so a second column offering the same action inherits the first one's
     *   placeholder instead of its own.
     * - A data column that merely happens to name such a field gets a route
     *   built onto it, and its value never renders.
     *
     * Both are silent in the browser, so they are refused here. And an action
     * column cannot be sorted, searched or filtered: those flags reach the
     * whitelist, and there is no column behind an icon to operate on.
     *
     * @param  list<ResolvedColumn>  $columns
     *
     * @throws InvalidDefinition
     */
    private static function assertActionsAreWellFormed(array $columns): void
    {
        /** @var array<string, string> $seen */
        $seen = [];

        foreach ($columns as $resolved) {
            $key = $resolved->key() ?? '';

            if ($resolved->isActionColumn()) {
                self::assertNotOperable($resolved, $key);
            }

            foreach ($resolved->declaredFields() as $field) {
                if (! Action::isActionField($field)) {
                    continue;
                }

                if (! $resolved->isActionColumn()) {
                    throw InvalidDefinition::actionFieldOutsideActionColumn($key, $field);
                }

                if (isset($seen[$field])) {
                    throw InvalidDefinition::duplicateAction($field, $seen[$field], $key);
                }

                $seen[$field] = $key;
            }
        }
    }

    /**
     * @throws InvalidDefinition
     */
    private static function assertNotOperable(ResolvedColumn $resolved, string $key): void
    {
        foreach (['sortable', 'searchable', 'filterable'] as $operation) {
            if ($resolved->flag($operation)) {
                throw InvalidDefinition::actionColumnOperable($key, $operation);
            }
        }

        if ($resolved->column->wantsGlobalSearch()) {
            throw InvalidDefinition::actionColumnOperable($key, 'globalSearch');
        }
    }
}
