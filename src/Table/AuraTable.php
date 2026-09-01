<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Table;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use TamasLabs\Aura\Cell\CellRules;
use TamasLabs\Aura\Exceptions\InvalidDefinition;
use TamasLabs\Aura\Query\AuraQuery;
use TamasLabs\Aura\Query\FieldPermissions;
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;
use TamasLabs\Aura\Response\NumericFields;
use TamasLabs\Aura\Response\RowPermissions;

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
     * The resource this table's actions hang off — `admin/users`.
     *
     * Only a **customised** action needs it. In convention mode the browser
     * builds the route from its own `urlParameter`, and the server never sees
     * that; the moment an action is customised the server has to emit the whole
     * configuration, route included, and this is where the base comes from.
     *
     * A relative path with no dots: Aura prefixes the host app's `siteName`
     * itself, and turns every dot into a slash.
     */
    protected ?string $resource = null;

    /**
     * {@see self::columns()}, called once.
     *
     * The list is asked for twice per request — once to build the definition,
     * once to collect the permission gates the definition cannot carry — and
     * {@see self::columns()} is documented as request-independent, so calling
     * it again would only rebuild the same objects.
     *
     * @var list<Column|ColumnGroup>|null
     */
    private ?array $entries = null;

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
     * Conditional styling of whole rows.
     *
     * Formatting only: `rowRules` cannot hide a row (`row-rules.zod.ts`), and
     * styling one away leaves its data in the payload. A row the user must not
     * see belongs outside {@see self::query()}.
     */
    public function rowRules(): ?CellRules
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

        $paginator = AuraQuery::paginate($this->query(), $aura);

        $data = AuraPayload::fromPaginator($paginator)->toArray();
        $data['items'] = NumericFields::coerce($data['items'], $blueprint->numericFields);

        // Last, and from the models rather than the rows: a policy wants the
        // object, and nothing after this may overwrite a permission flag.
        $data['items'] = $this->rowPermissions()->apply(
            array_values($paginator->items()),
            $data['items'],
        );

        return $blueprint->definition + $data;
    }

    /**
     * The per-row permission gates the columns declared.
     *
     * Built fresh every time, and deliberately outside {@see self::blueprint()}:
     * a gate is a closure and the blueprint is cached as plain arrays. What the
     * cache holds is the *name* of each flag, written into the definition as a
     * condition; what this holds is the callback that fills it.
     *
     * The two can only drift in one direction. A flag named in a cached
     * definition with no gate left to fill it is simply absent from the rows,
     * and an absent flag is not `true` — the cell stays hidden. A gate with no
     * flag adds an unread field. Neither reveals anything.
     *
     * @throws InvalidDefinition When two gates would write one flag.
     *
     * @internal
     */
    public function rowPermissions(): RowPermissions
    {
        $permissions = RowPermissions::make();

        foreach ($this->entries() as $entry) {
            $columns = $entry instanceof ColumnGroup ? $entry->columns() : [$entry];

            foreach ($columns as $column) {
                foreach ($column->rowPermissions() as $field => $gate) {
                    $permissions->add($field, $gate);
                }
            }
        }

        return $permissions;
    }

    /**
     * The describing half of the response and the fields it implies, from the
     * cache when caching is on.
     *
     * @internal
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
     * The route base an escalated action builds on. Override when it is not a
     * constant — one resource per tenant, say.
     */
    public function resource(): ?string
    {
        return $this->resource;
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
     * The assembly itself lives in {@see DefinitionBuilder}: this method is
     * about the *table* — its columns, its model, its settings — and stops
     * where the columns take over.
     *
     * @throws InvalidDefinition
     */
    private function build(): TableBlueprint
    {
        $entries = $this->entries();

        if ($entries === []) {
            throw InvalidDefinition::noColumns(static::class);
        }

        $builder = new DefinitionBuilder(
            entries: $entries,
            model: $this->query()->getModel(),
            settings: $this->settings(),
            footer: $this->footer(),
            rowRules: $this->rowRules(),
            resource: $this->resource(),
        );

        return $builder->build();
    }

    /**
     * The column list, memoised for the request.
     *
     * @return list<Column|ColumnGroup>
     */
    private function entries(): array
    {
        return $this->entries ??= $this->columns();
    }
}
