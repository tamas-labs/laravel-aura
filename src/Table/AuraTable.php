<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Query\AuraQuery;
use TamasLabs\Aura\Query\FieldPermissions;
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;

/**
 * One table, as a class.
 *
 * Extend it, say what to query and which columns to show, and the request is
 * served end to end:
 *
 * ```php
 * final class UserTable extends AuraTable
 * {
 *     public function query(): Builder
 *     {
 *         return User::query()->with('company');
 *     }
 *
 *     public function columns(): array
 *     {
 *         return [
 *             Column::selection(),
 *             Column::make('last_name')->sortable()->searchable()->globalSearch(),
 *             Column::make('company.name')->sortable(),
 *             Column::make('status')->filterable(),
 *             Column::make('created_at')->sortable()->searchable(),
 *         ];
 *     }
 * }
 *
 * // in the controller
 * return (new UserTable)->respond($request);
 * ```
 *
 * The columns are the single source of truth. What the browser is offered and
 * what the query layer will accept are derived from the same definition, so a
 * header cannot advertise a sort the server then refuses — the mismatch that a
 * hand-written header makes almost inevitable.
 *
 * @template TModel of Model
 */
abstract class AuraTable
{
    /**
     * Cache the request-independent half of the response.
     *
     * Off by default, because it is only safe once {@see self::columns()} is
     * genuinely request-independent: a definition that reads the current user,
     * the locale or a feature flag will be cached for whoever asked first.
     */
    protected bool $cache = false;

    /**
     * How long a cached definition lives, in seconds.
     */
    protected int $cacheTtl = 3600;

    /**
     * The query the table pages through. Constraints that are always true —
     * scoping to a tenant, eager loads — belong here.
     *
     * @return Builder<TModel>
     */
    abstract public function query(): Builder;

    /**
     * The columns, left to right.
     *
     * @return list<Column|ColumnGroup>
     */
    abstract public function columns(): array;

    /**
     * Table-wide settings. Override to change any of them.
     */
    public function settings(): TableSettings
    {
        return TableSettings::make();
    }

    /**
     * An optional footer.
     */
    public function footer(): ?Footer
    {
        return null;
    }

    /**
     * Serve one request: the definition, plus the page of data it asked for.
     *
     * @return array<string, mixed>
     */
    public function respond(Request $request): array
    {
        $blueprint = $this->blueprint();

        $aura = AuraRequest::fromHttp($request, $blueprint->permissions);

        $payload = AuraPayload::fromPaginator(AuraQuery::paginate($this->query(), $aura));

        return $blueprint->definition + $payload->toArray();
    }

    /**
     * The describing half of the response and the fields it implies, from the
     * cache when caching is on.
     */
    public function blueprint(): TableBlueprint
    {
        if (! $this->cache) {
            return $this->build();
        }

        $cached = Cache::get($this->cacheKey());

        // Anything but the array we stored means the entry is not ours or no
        // longer has the shape we wrote; rebuilding is always correct.
        if (is_array($cached)) {
            return TableBlueprint::fromArray($cached);
        }

        $blueprint = $this->build();

        Cache::put($this->cacheKey(), $blueprint->toArray(), $this->cacheTtl);

        return $blueprint;
    }

    /**
     * `header`, and `body` / `footer` when they carry anything.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return $this->blueprint()->definition;
    }

    /**
     * The fields this table's columns allow the client to operate on.
     */
    public function permissions(): FieldPermissions
    {
        return $this->blueprint()->permissions;
    }

    /**
     * Cache key for the definition. Override when one table class serves
     * several shapes — per locale, say.
     */
    public function cacheKey(): string
    {
        return 'aura.table.'.static::class;
    }

    /**
     * Drop the cached definition; call after a deploy that changes the columns.
     */
    public function forgetCache(): void
    {
        Cache::forget($this->cacheKey());
    }

    /**
     * Build the definition and the whitelist from the columns, in one pass so
     * the two cannot disagree.
     *
     * @throws InvalidDefinition
     */
    private function build(): TableBlueprint
    {
        $entries = $this->columns();

        if ($entries === []) {
            throw InvalidDefinition::noColumns(static::class);
        }

        $model = $this->query()->getModel();
        $grouped = self::isGrouped($entries);

        /** @var list<array<string, mixed>> $top */
        $top = [];
        /** @var list<array<string, mixed>> $second */
        $second = [];
        /** @var list<array{0: Column, 1: array<string, mixed>}> $columns */
        $columns = [];

        foreach ($entries as $entry) {
            if ($entry instanceof ColumnGroup) {
                $top[] = $entry->resolve();

                foreach ($entry->columns() as $column) {
                    $cell = $column->resolve($model);
                    $second[] = $cell;
                    $columns[] = [$column, $cell];
                }

                continue;
            }

            $cell = $entry->resolve($model);

            if ($grouped) {
                // Every data column has to live in the *last* header row; an
                // ungrouped one gets an empty cell above it rather than a
                // rowspan. See {@see self::spacer()}.
                $top[] = self::spacer($cell);
                $second[] = $cell;
            } else {
                $top[] = $cell;
            }

            $columns[] = [$entry, $cell];
        }

        self::assertKeysAreUnique($columns);

        return new TableBlueprint(
            definition: $this->definitionFrom($grouped ? [$top, $second] : [$top], $columns, $model),
            permissions: self::permissionsFrom($columns),
        );
    }

    /**
     * @param  list<list<array<string, mixed>>>  $rows
     * @param  list<array{0: Column, 1: array<string, mixed>}>  $columns
     * @param  TModel  $model
     * @return array<string, mixed>
     */
    private function definitionFrom(array $rows, array $columns, Model $model): array
    {
        $settings = $this->settings();

        $header = ['rows' => array_map(static fn (array $cells): array => ['cells' => $cells], $rows)];

        $headerSettings = $settings->headerSettings();
        $searchableItems = self::globalSearchFields($columns);

        if ($searchableItems !== []) {
            $headerSettings['searchableItems'] = $searchableItems;
        }

        if ($headerSettings !== []) {
            $header['settings'] = $headerSettings;
        }

        $definition = ['header' => $header];

        $body = self::bodyFrom($settings, $columns);

        if ($body !== []) {
            $definition['body'] = $body;
        }

        $footer = $this->footerFrom($settings, $model);

        if ($footer !== null) {
            $definition['footer'] = $footer;
        }

        return $definition;
    }

    /**
     * @param  list<array{0: Column, 1: array<string, mixed>}>  $columns
     * @return array<string, mixed>
     */
    private static function bodyFrom(TableSettings $settings, array $columns): array
    {
        $body = [];

        $bodySettings = $settings->bodySettings();

        if ($bodySettings !== []) {
            $body['settings'] = $bodySettings;
        }

        $styles = [];

        foreach ($columns as [$column, $cell]) {
            $class = $column->resolvedCellClass();

            if ($class !== null && isset($cell['key']) && is_string($cell['key'])) {
                $styles[$cell['key']] = $class;
            }
        }

        if ($styles !== []) {
            $body['columnStyles'] = $styles;
        }

        return $body;
    }

    /**
     * @param  TModel  $model
     * @return array<string, mixed>|null
     */
    private function footerFrom(TableSettings $settings, Model $model): ?array
    {
        $footer = $this->footer();

        if (! $footer instanceof Footer) {
            return null;
        }

        $rows = [];

        foreach ($footer->rows() as $cells) {
            $rows[] = ['cells' => array_map(
                static fn (Column $column): array => $column->resolve($model),
                $cells,
            )];
        }

        if ($rows === []) {
            return null;
        }

        $block = ['rows' => $rows];

        $footerSettings = $settings->footerSettings();

        if ($footerSettings !== []) {
            $block['settings'] = $footerSettings;
        }

        return $block;
    }

    /**
     * The whitelist, read back out of the cells the browser will receive.
     *
     * @param  list<array{0: Column, 1: array<string, mixed>}>  $columns
     */
    private static function permissionsFrom(array $columns): FieldPermissions
    {
        $sortable = [];
        $searchable = [];
        $filterable = [];

        foreach ($columns as [$column, $cell]) {
            if (self::flag($cell, 'sortable')) {
                $sortable[] = self::operableField($cell, 'sortable');
            }

            if (self::flag($cell, 'searchable')) {
                $searchable[] = self::operableField($cell, 'searchable');
            }

            if (self::flag($cell, 'filterable')) {
                $filterable[] = self::operableField($cell, 'filterable');
            }
        }

        return new FieldPermissions(
            sortable: $sortable,
            searchable: $searchable,
            filterable: $filterable,
            globalSearch: self::globalSearchFields($columns),
        );
    }

    /**
     * The field Aura will actually send for this cell.
     *
     * Aura resolves it as `reference || field || key`, so the whitelist has to
     * agree exactly — an entry naming the wrong one of the three turns every
     * click on that column into a 422.
     *
     * @param  array<string, mixed>  $cell
     *
     * @throws InvalidDefinition When a multi-field column names no reference.
     */
    private static function operableField(array $cell, string $operation): string
    {
        foreach (['reference', 'field'] as $candidate) {
            $value = $cell[$candidate] ?? null;

            if (is_string($value)) {
                return $value;
            }
        }

        $key = isset($cell['key']) && is_string($cell['key']) ? $cell['key'] : '';

        // Aura would fall back to the key, which for a multi-field column is a
        // name the database has never heard of.
        throw InvalidDefinition::multiFieldNeedsReference($key, $operation);
    }

    /**
     * The fields the global search covers — the same list the header publishes
     * as `searchableItems` and the query layer accepts.
     *
     * @param  list<array{0: Column, 1: array<string, mixed>}>  $columns
     * @return list<string>
     */
    private static function globalSearchFields(array $columns): array
    {
        $fields = [];

        foreach ($columns as [$column, $cell]) {
            if (! $column->wantsGlobalSearch()) {
                continue;
            }

            $field = $cell['field'] ?? null;

            if (! is_string($field)) {
                throw InvalidDefinition::multiFieldInGlobalSearch(
                    isset($cell['key']) && is_string($cell['key']) ? $cell['key'] : '',
                );
            }

            $fields[] = $field;
        }

        return $fields;
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

    /**
     * @param  list<Column|ColumnGroup>  $entries
     */
    private static function isGrouped(array $entries): bool
    {
        foreach ($entries as $entry) {
            if ($entry instanceof ColumnGroup) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0: Column, 1: array<string, mixed>}>  $columns
     *
     * @throws InvalidDefinition
     */
    private static function assertKeysAreUnique(array $columns): void
    {
        $seen = [];

        foreach ($columns as [$column, $cell]) {
            $key = $cell['key'] ?? null;

            if (! is_string($key)) {
                continue;
            }

            if (isset($seen[$key])) {
                throw InvalidDefinition::duplicateKey($key);
            }

            $seen[$key] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $cell
     */
    private static function flag(array $cell, string $key): bool
    {
        return (bool) ($cell[$key] ?? false);
    }
}
