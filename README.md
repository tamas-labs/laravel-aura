# laravel-aura

Laravel package that builds the JSON contract consumed by the
[Aura](https://github.com/tamas-labs/aura) Vue 3 data table — request in, Eloquent query out,
paginated payload back.

> 📖 **[Full reference documentation →](./README.en.md)** · 🇭🇺 **[Magyarul →](./README.hu.md)**
> This short README covers installation and the basics; the full reference (the whitelist, the
> request contract, the query semantics, relations, the response shape, and the development
> workflow) lives in [README.en.md](./README.en.md) (English) and [README.hu.md](./README.hu.md)
> (Hungarian).

## What it does

- 🔒 **Whitelisted fields** — every `field` in the request is attacker controlled, so nothing
  reaches the query except through `FieldPermissions`; an unlisted field is a 422, never a
  silently ignored parameter
- 🔎 **Search** — substring or exact, plus range searches with either end open
- 🎛 **Filter** — multi-value selection, with `NULL` handled rather than silently dropped
- 🌐 **Global search** across the fields the table declares, grouped so it cannot widen the
  other constraints
- ↕️ **Sort** on several keys, in the order the user added them
- 🔗 **Relations** in all four operations — `whereHas` at any depth for search and filter, a
  correlated subquery (never a join, which would corrupt `meta.total`) for sorting
- 📄 **`items` / `meta` / `links`** straight from a `LengthAwarePaginator`
- ✅ **Schema-validated** against [`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema)
  in the test suite, offline — no network call, and an unresolvable schema throws
- 🧪 **PHPStan level `max`**, Pint, Pest — the whole gate in one `composer quality`

## Status

The query side (**F2**) is complete and tested; the package is usable from here on. The
**definition core is not built yet** (F3), so the describing half of the response — `header` — is
a hand-written array you merge with the payload. See
[Status](./README.en.md#status) for what is and is not in place.

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
use TamasLabs\Aura\Query\{AuraQuery, FieldPermissions};
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;

$aura = AuraRequest::fromHttp($request, new FieldPermissions(
    sortable:     ['last_name', 'created_at', 'company.name'],
    searchable:   ['first_name', 'last_name', 'balance'],
    filterable:   ['status'],
    globalSearch: ['first_name', 'last_name'],
));

$payload = AuraPayload::fromPaginator(AuraQuery::paginate(User::query(), $aura));

return response()->json(['header' => $header] + $payload->toArray());
```

`$header` is yours to write until F3 — a
[minimal one](./README.en.md#the-other-half-of-the-response-header) is three lines, and the full
schema lives in the [aura-schema](https://github.com/tamas-labs/aura-schema) package.

Aura fetches with `POST` by default, so route it accordingly:

```php
Route::post('/users', UserTableController::class);
```

## Configuration

```php
// config/aura.php
return [
    'pagination' => [
        'max' => 100,     // hard ceiling on the client's `paginate`, clamped rather than rejected
    ],
];
```

There is deliberately no default page size: `paginate` is required by the contract, and
defaulting a missing one would turn a broken client into a silently short page instead of a 422.

## Two things worth knowing up front

**Only `paginate()` works.** The contract requires `meta.last_page` and `meta.total`, which
neither `simplePaginate()` nor `cursorPaginate()` can supply — passing one raises
`UnsupportedPaginator` rather than emitting a payload the table cannot read.

**Sorting through a relation is limited to one to-one level.** A join would multiply rows on a
to-many relation and corrupt the pagination itself, so sorting uses a correlated subquery
instead. Searching and filtering have no such limit.

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
