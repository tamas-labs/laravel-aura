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
- 🪄 **Defaults inferred from the model** — a `decimal` cast means money, a `datetime` cast means
  a range search, an enum cast means exactly which options the filter may offer
- 🔎 **Search** (substring, exact, or a range with either end open), **filter** with `NULL`
  handled rather than silently dropped, and a **global search** grouped so it cannot widen the
  other constraints
- 🔗 **Relations** in all four operations — `whereHas` at any depth for search and filter, a
  correlated subquery (never a join, which would corrupt `meta.total`) for sorting
- 🧩 **Grouped headers, footers, presets**, and a `Macroable` column builder
- ⚡ **Cacheable definition** — the header, body and footer do not depend on the request
- ✅ **Schema-validated** against [`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema)
  in the test suite, offline — no network call, and an unresolvable schema throws
- 🧪 **PHPStan level `max`**, Pint, Pest — the whole gate in one `composer quality`

## Status

The definition core (**F3**) is complete: a table is a class and serves a request end to end.
Cells render as plain text until **F4** brings the nine renderers (badge, link, progress, …); the
action layer and per-row permissions come in **F5**. See
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
            Column::make('status')->filterable(),      // options inferred from the enum cast
            Column::make('balance')->sortable(),       // currency + right-aligned from the decimal cast
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
        'max' => 100,     // hard ceiling on the client's `paginate`, clamped rather than rejected
    ],
];
```

There is deliberately no default page size: `paginate` is required by the contract, and defaulting
a missing one would turn a broken client into a silently short page instead of a 422.

## Three things worth knowing up front

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
