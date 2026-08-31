# laravel-aura

Laravel package that builds the JSON contract consumed by the
[Aura](https://github.com/tamas-labs/aura) Vue 3 data table. Declare the table once, as a class,
and it answers the request.

> 📖 **[Full reference documentation →](./README.en.md)** · 🇭🇺 **[Magyarul →](./README.hu.md)**
> This short README covers installation and the basics; the full reference (columns, inference,
> enums, presets, grouped headers, caching, and the query layer underneath) lives in
> [README.en.md](./README.en.md) (English) and [README.hu.md](./README.hu.md) (Hungarian).

## What it does

- 🧱 **One class per table** — `query()` and `columns()`, and the endpoint is `respond($request)`
- 🔒 **The whitelist comes from the columns** — every `field` in the request is attacker
  controlled, and nothing reaches the query except through a column that offered it; an unlisted
  field is a 422, never a silently ignored parameter
- 🧯 **Every list and string in a request is bounded** — and the three field lists need no config
  key for it, because the whitelist is already their exact ceiling
- 🪄 **Defaults inferred from the model** — a `decimal` cast means money, a `datetime` cast means
  a range search, an enum cast means exactly which options the filter may offer
- 🔎 **Search** (substring, exact, or a range with either end open), **filter** with `NULL`
  handled rather than silently dropped, and a **global search** grouped so it cannot widen the
  other constraints
- 🔗 **Relations** in all four operations — `whereHas` at any depth for search and filter, a
  correlated subquery (never a join, which would corrupt `meta.total`) for sorting
- 🎨 **Nine cell renderers** — badge, link, button, icon, modal, progress, and the rest — with
  per-row conditions, and a table that refuses the four ways Aura fails silently
- 🧩 **Grouped headers, footers, presets**, and `Macroable` builders throughout
- ⚡ **Cacheable definition** — the header, body and footer do not depend on the request
- ✅ **Schema-validated** against [`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema)
  in the test suite, offline — no network call, and an unresolvable schema throws
- 🧪 **PHPStan level `max`**, Pint, Pest — the whole gate in one `composer quality`

## Status

The definition core (**F3**), the cell builders (**F4**) and the action layer (**F5a**, **F5b**)
are complete: a table is a class, serves a request end to end, renders badges, links, progress bars
and the rest conditionally per row, and offers the four resource actions in one call — with routes
from a `$resource`, a named route, or spelled out. Per-row permissions come in **F5c**. See
[Status](./README.en.md#status) for the full picture.

Not released: no tag, not on Packagist.

## Requirements

PHP 8.3+ · Laravel 12 or 13

## Installation

```json
{
    "repositories": [
        { "type": "vcs", "url": "https://github.com/tamas-labs/laravel-aura" }
    ]
}
```

```bash
composer require tamas-labs/laravel-aura:dev-main
```

The service provider is registered by package discovery. Publish the config only if you want to
change the page-size ceiling:

```bash
php artisan vendor:publish --tag=aura-config
```

## Quick start

```php
use TamasLabs\Aura\Cell\{Badge, Condition, Reference};
use TamasLabs\Aura\Table\{AuraTable, Column};

/**
 * @extends AuraTable<User>
 */
final class UserTable extends AuraTable
{
    public function query(): Builder
    {
        return User::query()->with('company');
    }

    public function columns(): array
    {
        return [
            Column::selection(),
            Column::make('last_name')->sortable()->searchable()->globalSearch(),
            Column::make('company.name', 'Company')->sortable(),
            Column::make('status')->filterable()       // options inferred from the enum cast
                ->as(Badge::fromEnum(Status::class)),   // …and the badge built from the same one
            Column::make('balance')->sortable()->as(   // currency + right-aligned from the decimal cast
                Reference::make()->when(Condition::lt(0), fn (Reference $r) => $r->color('danger'))
            ),
            Column::make('created_at')->sortable()->searchable(),
        ];
    }
}
```

```php
Route::post('/users', fn (Request $request) => (new UserTable)->respond($request));
```

That is the whole endpoint. Aura fetches with `POST` by default, so route it accordingly.

## Configuration

```php
// config/aura.php
return [
    'pagination' => [
        'max' => 100,          // hard ceiling on the client's `paginate`, clamped rather than rejected
    ],

    'limits' => [
        'selected' => 1000,    // ids in `selected[]`
        'values' => 200,       // values in one filter dropdown
        'term' => 255,         // characters in `globalSearch` and `searchable[].term`
    ],
];
```

There is deliberately no default page size: `paginate` is required by the contract, and defaulting
a missing one would turn a broken client into a silently short page instead of a 422.

The `sortable`, `searchable` and `filterable` lists have no key here because they need none: Aura
sends at most one entry per field, so the column whitelist is already their exact ceiling. See
[What bounds a request](./README.en.md#what-bounds-a-request).

## Four things worth knowing up front

**The header and the whitelist are one definition.** What the browser is offered and what the
query accepts are derived from the same columns, resolving the field exactly as Aura does
(`reference || field || key`). Keeping two lists in agreement by hand is the mistake this exists
to prevent.

**Only `paginate()` works.** The contract requires `meta.last_page` and `meta.total`, which
neither `simplePaginate()` nor `cursorPaginate()` can supply — passing one raises
`UnsupportedPaginator` rather than emitting a payload the table cannot read.

**Sorting through a relation is limited to one to-one level.** A join would multiply rows on a
to-many relation and corrupt the pagination itself, so sorting uses a correlated subquery instead.
Searching and filtering have no such limit.

**The definition refuses what Aura would render wrongly in silence.** A cell configuration keyed
where Aura will not look for it, conditions with no field to read, an absolute route, nesting past
Aura's recursion cap — each of these produces a payload that validates against the schema and then
does nothing, so the package raises `InvalidDefinition` on the server instead. See
[what the table refuses to build](./README.en.md#what-the-table-refuses-to-build).

## Development

**There is no PHP and no Composer on the host** — everything runs in Docker:

```bash
docker compose run --rm php composer install
docker compose run --rm php composer quality     # pint + phpstan + pest
docker compose run --rm php vendor/bin/pest
```

One service, no database container — the suite runs on in-memory SQLite.

## Documentation

📖 **[README.en.md](./README.en.md)** — full reference (English).

🇭🇺 **[README.hu.md](./README.hu.md)** — the same in Hungarian.

📝 **[CHANGELOG.md](./CHANGELOG.md)** — change history.

## License

MIT

## Author

Tamas Balint
