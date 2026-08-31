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

        $payload = AuraPayload::fromPaginator(AuraQuery::paginate($this->query(), $aura));

        $data = $payload->toArray();
        $data['items'] = NumericFields::coerce($data['items'], $blueprint->numericFields);

        return $blueprint->definition + $data;
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
     * The assembly itself lives in {@see DefinitionBuilder}: this method is
     * about the *table* — its columns, its model, its settings — and stops
     * where the columns take over.
     *
     * @throws InvalidDefinition
     */
    private function build(): TableBlueprint
    {
        $entries = $this->columns();

        if ($entries === []) {
            throw InvalidDefinition::noColumns(static::class);
        }

        $builder = new DefinitionBuilder(
            entries: $entries,
            model: $this->query()->getModel(),
            settings: $this->settings(),
            footer: $this->footer(),
            rowRules: $this->rowRules(),
        );

        return $builder->build();
    }
}
