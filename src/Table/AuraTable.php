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
use TamasLabs\Aura\Response\MissingFields;
use TamasLabs\Aura\Response\NumericFields;
use TamasLabs\Aura\Response\RowFields;
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
     * Send only the fields the definition names.
     *
     * A row is the model as `toArray()` renders it — everything the model is
     * not hiding, whether or not a column reads it. That is more than the table
     * asked for in two ways: a five-column table over a twenty-five-column
     * model sends twenty unread values per row, and `->with('company')` sends
     * every column of the company beside them. The model's `$hidden` is the
     * only thing standing between an unlisted `notes` or `internal_score` and
     * the browser, and the table definition never mentions it.
     *
     * Switching this on narrows each row to what the emitted definition names —
     * fields, references, keys, condition fields and route placeholders, read
     * back out of the definition the browser receives rather than declared a
     * second time. It applies to whatever {@see self::transform()} returned, so
     * the two compose.
     *
     * **Off by default, and that is a compatibility decision rather than a
     * preference.** The payload's shape is public surface, so narrowing it for
     * everyone is a major version. A hand-written `merge()` payload can also
     * name a field nothing else in the definition mentions, and that field
     * would be dropped; {@see self::transform()} is the answer that cannot
     * guess wrong.
     */
    protected bool $onlyDeclaredFields = false;

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
     * The model the definition is described against, resolved once.
     *
     * Column inference reads casts and relations off a model, and the only
     * place a table names one is {@see self::query()}. Asking for a second
     * builder just to reach `getModel()` would run `query()` twice per request,
     * and a `query()` that reads the current user, counts or logs would do all
     * of that twice; {@see self::respond()} therefore fills this in from the
     * builder the request already has.
     *
     * Sharing that model with the builder about to be paginated is safe in both
     * directions: {@see AuraQuery} mutates the *builder* — wheres, orders,
     * subqueries — never the model, and the definition only ever reads from it.
     *
     * @var TModel|null
     */
    private ?Model $model = null;

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
     * One row, as the browser receives it.
     *
     * The whole model by default. Override to send less — an API resource, an
     * `->only()`, a `makeHidden()` — or to add something computed:
     *
     * ```php
     * protected function transform(Model $model): array
     * {
     *     return $model->only(['id', 'first_name', 'last_name', 'status']);
     * }
     * ```
     *
     * Two things are added *after* this and cannot be removed here: the numeric
     * coercion the conditions need, and the per-row permission flags. Both read
     * the definition, so neither can be satisfied by a row this returns.
     *
     * @param  TModel  $model
     * @return array<string, mixed>
     */
    protected function transform(Model $model): array
    {
        /** @var array<string, mixed> $row */
        $row = $model->toArray();

        return $row;
    }

    /**
     * Serve one request: the definition, plus the page of data it asked for.
     *
     * @return array<string, mixed>
     */
    public function respond(Request $request): array
    {
        // One `query()` per request. The definition needs the model this
        // builder already carries, not a builder of its own — see
        // {@see self::$model} for why handing it this one is safe.
        $query = $this->query();
        $this->model ??= $query->getModel();

        $blueprint = $this->blueprint();

        $aura = AuraRequest::fromHttp($request, $blueprint->permissions);

        $paginator = AuraQuery::paginate($query, $aura);

        $data = AuraPayload::fromPaginator($paginator, $this->transform(...))->toArray();

        if ($this->onlyDeclaredFields) {
            // Read out of the definition the browser is about to receive — the
            // cached one when caching is on — so the rows cannot be narrowed to
            // a different set of fields than the table was described with.
            $data['items'] = RowFields::fromDefinition($blueprint->definition)->narrowAll($data['items']);
        }

        $data['items'] = NumericFields::coerce($data['items'], $blueprint->numericFields);

        // Last, and from the models rather than the rows: a policy wants the
        // object, and nothing after this may overwrite a permission flag.
        $data['items'] = $this->rowPermissions()->apply(
            array_values($paginator->items()),
            $data['items'],
        );

        // Development only, and last: read against the rows the browser is
        // actually getting, so a relation nothing loaded is a line in the log
        // rather than a blank column with no explanation.
        MissingFields::report($blueprint->definition, $data['items'], static::class);

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
            model: $this->model(),
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

    /**
     * The model the definition is built against, memoised for the request.
     *
     * Only reached when nothing has filled {@see self::$model} in yet — a
     * {@see self::definition()} or {@see self::permissions()} call on its own,
     * where there is no request builder to take it from.
     *
     * @return TModel
     */
    private function model(): Model
    {
        return $this->model ??= $this->query()->getModel();
    }
}
