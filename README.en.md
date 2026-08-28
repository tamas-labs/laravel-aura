# laravel-aura — full reference

Laravel package that builds the JSON contract consumed by the
[Aura](https://github.com/tamas-labs/aura) Vue 3 data table.

> 🇭🇺 **[Magyarul →](./README.hu.md)** · 📄 **[Short README →](./README.md)**

Aura's table is driven entirely by JSON: the endpoint tells it what columns exist, how each cell
renders, and which rows to show. This package is the server half of that conversation — it reads
the request Aura sends, turns it into an Eloquent query, and returns the paginated data in the
shape the contract requires.

---

## Table of contents

- [Status](#status)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [The three steps](#the-three-steps)
- [FieldPermissions — the whitelist](#fieldpermissions--the-whitelist)
- [AuraRequest — reading the request](#aurarequest--reading-the-request)
- [AuraQuery — building the query](#auraquery--building-the-query)
- [AuraPayload — the response data](#aurapayload--the-response-data)
- [The other half of the response: `header`](#the-other-half-of-the-response-header)
- [Exceptions](#exceptions)
- [A complete controller](#a-complete-controller)
- [The wire contract](#the-wire-contract)
- [Validating your own payloads](#validating-your-own-payloads)
- [Development](#development)
- [Roadmap](#roadmap)
- [License](#license)

---

## Status

The package is at **F2** of its plan: the query side is complete and tested, and the package is
usable from here on. What that means concretely:

| Works today | Not built yet |
| --- | --- |
| Reading and validating the Aura request | `AuraTable` / `Column` definition objects (F3) |
| Sorting, searching, filtering, global search | Column inference from model casts (F3) |
| Relations in all four operations | Cell builders — badge, link, progress, … (F4) |
| `items` / `meta` / `links` from a paginator | Action columns and per-row permissions (F5) |
| Contract validation in the test suite | A generated `header` — you write it by hand (F3) |

Until F3 lands, the describing half of the response (`header`, and optionally `body` / `footer`)
is a hand-written array that you merge with the payload. That is enough for a working table —
see [A complete controller](#a-complete-controller).

The package is **not released**: no tag, not on Packagist. Install it from the repository.

---

## Requirements

- **PHP** 8.3 or 8.4
- **Laravel** 12 or 13 (`illuminate/support`, `illuminate/contracts`)
- A database driver Eloquent supports; the test suite runs on SQLite, the `LIKE` escaping is
  written to behave identically on MySQL/MariaDB, PostgreSQL and SQLite

---

## Installation

The package is not on Packagist, so point Composer at the repository:

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

`AuraServiceProvider` is registered by package discovery — nothing to add to `bootstrap/providers.php`.

Publish the config if you want to change the page-size ceiling:

```bash
php artisan vendor:publish --tag=aura-config
```

---

## Configuration

`config/aura.php` has exactly one key today:

```php
return [
    'pagination' => [
        'max' => 100,
    ],
];
```

**`pagination.max`** is the hard upper bound on the `paginate` value arriving from the client. It
is a ceiling, not a suggestion: `paginate` is attacker controlled, and without a bound a single
request can ask for the whole table.

An oversized value is **clamped, not rejected**. A stale client that still remembers a page size
of 500 keeps working at 100 instead of erroring at the user — the ceiling protects the database,
and there is nothing to gain by also breaking the page.

There is deliberately **no default page size**. The request contract makes `paginate` required;
defaulting a missing one would turn a broken client into a silently short page instead of a 422.

The ceiling can be overridden per call, which is useful for an export endpoint:

```php
AuraRequest::fromHttp($request, $fields, maxPaginate: 5000);
```

---

## The three steps

```php
use TamasLabs\Aura\Query\{AuraQuery, FieldPermissions};
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;

// 1. read the request — validated and whitelisted
$aura = AuraRequest::fromHttp($request, new FieldPermissions(
    sortable:     ['last_name', 'created_at', 'company.name'],
    searchable:   ['first_name', 'last_name', 'balance'],
    filterable:   ['status'],
    globalSearch: ['first_name', 'last_name', 'company.name'],
));

// 2. apply it to a query
$paginator = AuraQuery::paginate(User::query(), $aura);

// 3. shape the response
$payload = AuraPayload::fromPaginator($paginator);
```

Each step is independent. `AuraQuery::apply()` returns the constrained builder if you want to
paginate yourself; `AuraPayload::fromPaginator()` accepts any length-aware paginator, whether or
not `AuraQuery` produced it.

---

## FieldPermissions — the whitelist

Every `field` in an Aura request comes from the browser. Passing one straight to `orderBy()`
leaks the existence — and, through sorting, the ordering — of columns the table never meant to
expose. `FieldPermissions` is the whitelist, and it is the **only** way a client-supplied field
reaches the query.

```php
new FieldPermissions(
    sortable:     ['last_name', 'created_at', 'company.name'],
    searchable:   ['first_name', 'last_name'],
    filterable:   ['status'],
    globalSearch: ['first_name', 'last_name'],
);
```

| Argument | Governs |
| --- | --- |
| `sortable` | which fields `sortable[].field` may name |
| `searchable` | which fields `searchable[].field` may name |
| `filterable` | which fields `filterable[].field` may name |
| `globalSearch` | which fields the toolbar's global search box covers |

Four properties hold, and each has a test pinning it:

- **The lists are separate.** A field being searchable does not make it sortable.
- **An empty list allows nothing.** There is no "allow everything" switch, and
  `FieldPermissions::none()` — nothing allowed — is the safe starting point.
- **Matching is exact.** `last` is not allowed by `last_name`; a prefix of an allowed name is
  rejected like any other unknown field.
- **A rejected field is a 422**, never a silently ignored parameter. The error names the field
  that was refused but never lists the permitted ones — an error response is not a place to
  enumerate the schema.

`globalSearch` is different in kind from the other three: it is not checked against a request
value, it *is* the list of fields the search runs over. The client sends only a term.

Dotted names (`company.name`) are permitted and resolve through the relation — see
[Relations](#relations).

---

## AuraRequest — reading the request

```php
AuraRequest::fromHttp(Request $request, FieldPermissions $fields, ?int $maxPaginate = null): self
AuraRequest::fromArray(array $payload, FieldPermissions $fields, ?int $maxPaginate = null): self
```

`fromHttp` reads the payload from where the contract puts it: the JSON body on `POST` / `PUT` /
`PATCH`, the query parameters on `GET` / `DELETE`. `fromArray` takes an already-decoded array,
for a queued job or a test.

Both throw `Illuminate\Validation\ValidationException` on anything the contract does not allow,
which Laravel renders as a **422** — never a 500, and never a silently dropped parameter.

### The request shape

| Key | Type | Required | Notes |
| --- | --- | --- | --- |
| `page` | integer ≥ 1 | **yes** | |
| `paginate` | integer ≥ 1 | **yes** | clamped to `pagination.max` |
| `sortable[]` | `{field, direction}` | no | `direction` is `asc` or `desc` |
| `searchable[]` | `{field, term?, exact?, min?, max?}` | no | term search or range search |
| `filterable[]` | `{field, values[]}` | no | `values` may be empty, but must be present |
| `globalSearch` | string | no | |
| `selected[]` | string / number | no | row ids for bulk actions |

Unknown properties are rejected — at the top level *and* inside the nested objects, because the
schema sets `additionalProperties: false` on both. The nested check runs on the raw payload on
purpose: Laravel's validator drops every key it has no rule for, so by the time validation is
done an unknown nested key has already vanished and could never be reported.

### The parsed result

```php
$aura->page;         // int
$aura->paginate;     // int, already clamped
$aura->sortable;     // list<Sort>    — field, direction
$aura->searchable;   // list<Search>  — field, term, exact, min, max, isRange()
$aura->filterable;   // list<Filter>  — field, values
$aura->globalSearch; // ?string
$aura->selected;     // list<string|int|float>
$aura->fields;       // the FieldPermissions it was built with
```

### `selected` never touches the query

`selected[]` names the rows the user has ticked, for the caller's own bulk actions. It is exposed
on the DTO and goes nowhere near the query — a test pins that the generated SQL is byte-identical
with and without it. Filtering the page down to the selection would be wrong twice over: the
selection can span pages, and the user did not ask for it.

```php
User::whereKey($aura->selected)->each->archive();
```

### `exact` in a query string

A query string carries every value as text, `exact=true` included, and Laravel's `boolean` rule
does not accept the word `"true"`. The query-parameter path therefore decodes that one value
before validating. The JSON-body path does not: there a string `"true"` really is a contract
violation, and stays one.

---

## AuraQuery — building the query

```php
AuraQuery::apply(Builder $query, AuraRequest $request): Builder
AuraQuery::paginate(Builder $query, AuraRequest $request): LengthAwarePaginator
```

`apply()` adds searches, filters, the global search and sorts to the builder you pass, and
returns it. `paginate()` does the same and then paginates with the request's `page` and
`paginate`.

Nothing in this class re-checks authorisation — every field reaching it has already passed the
whitelist — but nothing in it accepts a field from anywhere else either.

### Searching

A `searchable[]` entry is either a **term search** or a **range search**:

| Sent | SQL |
| --- | --- |
| `{field, term}` | `LIKE '%term%'` — substring, wildcards escaped |
| `{field, term, exact: true}` | `= 'term'` |
| `{field, min, max}` | `>= min` and `<= max` |
| `{field, min}` | `>= min` — open upper end |
| `{field, max}` | `<= max` — open lower end |

`null` at either end of a range means *unbounded*, not *match null*. An empty or missing term
adds no constraint at all rather than matching everything.

**Wildcards in the term are escaped.** Without that, a term of `%` matches every row — not an
injection (the term is still bound), but a search box that quietly turns into a full table scan,
and a search for `100%` that also returns `1000`.

The escape character is `!`, not a backslash. MySQL and SQLite disagree on whether a backslash
inside a string literal is itself an escape, so `ESCAPE '\'` means different things on each; `!`
is one character in every dialect. `AuraQuery::likeExpression()` is the only raw SQL in the
package and carries the reasoning — the column comes from the whitelist and is then wrapped by
the grammar, and the term travels as a binding, never interpolated.

### Filtering

`{field, values}` matches a row whose column equals any of the values.

- **`null` among the values** adds `OR column IS NULL`. `IN (…)` never matches `NULL`, so a
  selected "no value" would otherwise silently drop exactly the rows the user asked for.
- **An empty `values` array matches nothing**, which is what an empty selection means. This is
  why the validation rule is `present` rather than `required` — Laravel treats an empty array as
  missing, while the contract requires the key and allows an empty selection.

### Global search

One term, `OR`-ed across every field in `FieldPermissions::$globalSearch`, using the same escaped
`LIKE` as a term search. The ORs are wrapped in their own nested `where` so they cannot escape
and widen the per-column constraints around them — a test pins that a global search cannot
resurrect rows a filter excluded.

### Sorting

Sorts are applied in the order the client sent them, so a second sort key breaks ties in the
first.

### Relations

A dotted field resolves through the relation it names:

| Operation | Mechanism | Depth | Relation types |
| --- | --- | --- | --- |
| Search | `whereHas` | any | any |
| Filter | `whereHas` | any | any |
| Global search | `orWhereHas` | any | any |
| **Sort** | **correlated subquery** | **one level** | **`BelongsTo`, `HasOne`** |

Sorting is the restricted one, deliberately. A join would read more naturally, but it multiplies
rows on a to-many relation — which corrupts `meta.total` and the contents of every page, breaking
pagination itself. A correlated subquery has no such effect and needs no select-list surgery; the
price is that it only answers for a to-one relation, one level deep.

Anything else raises `UnsupportedRelation` at development time, with a concrete suggestion:

```
Cannot sort by "posts.title": posts.title is a HasMany, and a to-many relation has no single
value to order on. Expose the value as a real column (a counter cache or a computed column)
and sort on that.
```

---

## AuraPayload — the response data

```php
AuraPayload::fromPaginator(Paginator|CursorPaginator $paginator): self
$payload->toArray(): array   // ['items' => …, 'meta' => …, 'links' => …]
```

`items` are the rows flattened to plain data (anything `Arrayable` gets `toArray()`), re-indexed
with `array_values` — a paginator page with gaps in its keys would serialise to a JSON object
instead of an array, and the contract types `items` as an array.

`meta` carries `current_page`, `from`, `last_page`, `path`, `per_page`, `to`, `total`; `links`
carries `first`, `last`, `prev`, `next`.

**Only `LengthAwarePaginator` works.** Aura's contract requires `meta.last_page` and `meta.total`,
and neither `simplePaginate()` nor `cursorPaginate()` knows them, because neither runs the count
query. Passing one raises `UnsupportedPaginator` rather than emitting a payload the table cannot
read:

```
Aura needs a LengthAwarePaginator, got Illuminate\Pagination\Paginator. The response contract
requires meta.last_page and meta.total, which simplePaginate() and cursorPaginate() cannot
supply — use paginate().
```

---

## The other half of the response: `header`

`AuraPayload` is the data half. On its own it is **not a valid response** — the contract requires
`header`, which describes the columns. Until F3 generates it, write it by hand and merge:

```php
$header = [
    'rows' => [[
        'cells' => [
            ['content' => '#',       'key' => 'id',        'field' => 'id', 'sortable' => true],
            ['content' => 'Name',    'key' => 'last_name', 'field' => 'last_name',
             'sortable' => true, 'searchable' => true],
            ['content' => 'Status',  'key' => 'status',    'field' => 'status',
             'filterable' => true, 'elements' => ['active' => 'Active', 'suspended' => 'Suspended']],
        ],
    ]],
];

return response()->json(['header' => $header] + $payload->toArray());
```

Two things to keep aligned by hand until F3:

- **The header and the whitelist must agree.** A column marked `sortable` in the header but
  missing from `FieldPermissions::$sortable` produces a table whose sort arrows return a 422.
- **`header.settings.searchableItems`** lists the fields the global search box covers on the
  client; it should match `FieldPermissions::$globalSearch`.

The full header, body and footer schemas live in
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema), with a complete worked
example under `schema/examples/response.json`.

---

## Exceptions

Every exception this package raises on its own behalf implements
`TamasLabs\Aura\Exceptions\AuraException`, so a host application can catch the lot at once:

| Exception | Raised when |
| --- | --- |
| `UnsupportedRelation` | sorting through a to-many relation, or a nested relation path |
| `UnsupportedPaginator` | the paginator cannot report `last_page` / `total` |

These all report a mistake in the **table definition**, never in the client's input — malformed
input fails validation and becomes a 422 long before it reaches them. That is why they are
runtime exceptions and not something to catch per request.

---

## A complete controller

```php
<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use TamasLabs\Aura\Query\{AuraQuery, FieldPermissions};
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;

final class UserTableController
{
    public function __invoke(Request $request)
    {
        $aura = AuraRequest::fromHttp($request, new FieldPermissions(
            sortable:     ['last_name', 'created_at', 'company.name'],
            searchable:   ['first_name', 'last_name', 'balance'],
            filterable:   ['status'],
            globalSearch: ['first_name', 'last_name', 'company.name'],
        ));

        $payload = AuraPayload::fromPaginator(
            AuraQuery::paginate(User::query()->with('company'), $aura),
        );

        return response()->json(['header' => $this->header()] + $payload->toArray());
    }

    private function header(): array
    {
        return ['rows' => [['cells' => [
            ['content' => 'Name',    'key' => 'last_name',  'field' => 'last_name',  'sortable' => true, 'searchable' => true],
            ['content' => 'Company', 'key' => 'company',    'field' => 'company.name', 'sortable' => true],
            ['content' => 'Status',  'key' => 'status',     'field' => 'status',     'filterable' => true],
            ['content' => 'Created', 'key' => 'created_at', 'field' => 'created_at', 'sortable' => true, 'datetime' => true],
        ]]]];
    }
}
```

Aura defaults to `POST` for its fetches, so route it accordingly (or set `requestMethod` on the
client side):

```php
Route::post('/users', UserTableController::class);
```

Note the `with('company')` — it has nothing to do with sorting through the relation (the subquery
handles that), it just avoids an N+1 when the rows render the company name.

---

## The wire contract

The contract version this package targets is `TamasLabs\Aura\AuraContract::VERSION`, currently
**1.0**.

The schema documents themselves are **not in this repository**. They live in
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema) — one canonical set, shared
by the Vue package and this one — and arrive here as a **dev dependency**.

That it is dev-only is deliberate: runtime validation belongs in the host application, and
Composer reads `repositories` only from the *root* package, so a runtime `require` of an
unpublished package would be unresolvable for anyone installing this one. Hence a `suggest` entry
rather than a `require`.

`AuraContract::VERSION` restates `AuraSchema::VERSION` because runtime code cannot read a dev
dependency; a test fails the moment the two disagree.

Nothing carries the version over the wire today. The response schema sets
`additionalProperties: true`, so a version field can be added later without a breaking change.

---

## Validating your own payloads

This package validates its own output against the schema in its test suite, with no network
access: `opis/json-schema` is pointed at the packaged documents, and the `$id` URLs are mapped
onto the local directory so they are identity, never a download. A document the resolver cannot
find **throws**, so a green contract test can never mean "schema not found".

You can do the same in your application's tests:

```bash
composer require --dev tamas-labs/aura-schema opis/json-schema
```

```php
use Opis\JsonSchema\Validator;
use TamasLabs\AuraSchema\AuraSchema;

$validator = new Validator;
$validator->resolver()?->registerPrefix(AuraSchema::BASE_URI, AuraSchema::directory());

$result = $validator->validate(
    json_decode($response->getContent()),        // objects, not arrays — see below
    AuraSchema::BASE_URI.'/aura-response.schema.json',
);
```

Decode fixtures to **objects**, not associative arrays: `json_decode(…, true)` erases the
difference between an empty object and an empty array, and JSON Schema cares about it.

---

## Development

**There is no PHP and no Composer on the host** — everything runs in Docker:

```bash
docker compose run --rm php composer install     # install dependencies
docker compose run --rm php composer quality     # pint + phpstan + pest — what CI runs
docker compose run --rm php vendor/bin/pest      # the test suite
docker compose run --rm php vendor/bin/pest --filter "clamps paginate"
docker compose run --rm php vendor/bin/phpstan analyse
docker compose run --rm php vendor/bin/pint      # apply formatting
```

One service, `php`, on `php:8.4-cli-alpine`. **No database container** — the suite runs on
in-memory SQLite, so `docker compose up` is never needed.

The quality gate is Laravel Pint (`laravel` preset), PHPStan/Larastan at level **max** over `src/`
and `tests/`, and Pest. CI runs the matrix natively (PHP 8.3/8.4 × Laravel 12/13) and separately
builds this image so the Dockerfile cannot rot.

---

## Roadmap

| Phase | Subject | State |
| --- | --- | --- |
| **F0** | Docker and repository scaffold | ✅ done |
| **F1** | Contract test harness | ✅ done |
| **F2** | Query side — request → Eloquent → `items`/`meta`/`links` | ✅ done |
| **F3** | Definition core: `AuraTable`, `Column`, inference from model casts, cacheable `header` | planned |
| **F4** | Cell builders: badge, link, button, icon, modal, progress, conditional configuration | planned |
| **F5** | Action layer (`edit` / `show` / `destroy`) and per-row permissions | planned |
| **F6** | Demo workbench app, `make:aura-table`, release | planned |

F3 is what removes the hand-written header: a column definition becomes one line, inferred from
the model's casts, and the request-independent half of the response becomes cacheable.

---

## License

MIT

## Author

Tamas Balint
