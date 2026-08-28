# laravel-aura — full reference

Laravel package that builds the JSON contract consumed by the
[Aura](https://github.com/tamas-labs/aura) Vue 3 data table.

> 🇭🇺 **[Magyarul →](./README.hu.md)** · 📄 **[Short README →](./README.md)**

Aura's table is driven entirely by JSON: the endpoint tells it what columns exist, how each cell
renders, and which rows to show. This package is the server half of that conversation. You declare
the table once, as a class, and it answers the request: the header the browser renders and the
fields the query will accept come out of the same definition, so they cannot drift apart.

---

## Table of contents

- [Status](#status)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Defining a table](#defining-a-table)
- [Columns](#columns)
- [Inference](#inference)
- [Enums](#enums)
- [Presets](#presets)
- [Cell rendering](#cell-rendering)
  - [The nine types](#the-nine-types)
  - [Multi-field columns](#multi-field-columns)
  - [Conditions](#conditions)
  - [Numeric comparisons](#numeric-comparisons)
  - [Cell and row rules](#cell-and-row-rules)
  - [Routes](#routes)
  - [Bootstrap in the contract](#bootstrap-in-the-contract)
- [Grouped headers](#grouped-headers)
- [Footers and settings](#footers-and-settings)
- [Caching the definition](#caching-the-definition)
- [What the table refuses to build](#what-the-table-refuses-to-build)
- [The query layer on its own](#the-query-layer-on-its-own)
  - [FieldPermissions](#fieldpermissions)
  - [AuraRequest](#aurarequest)
  - [AuraQuery](#auraquery)
  - [AuraPayload](#aurapayload)
- [Exceptions](#exceptions)
- [The wire contract](#the-wire-contract)
- [Validating your own payloads](#validating-your-own-payloads)
- [Development](#development)
- [Roadmap](#roadmap)
- [License](#license)

---

## Status

The package is at **F4** of its plan: a table is a class, it serves a request end to end, and its
cells render as more than text.

| Works today | Not built yet |
| --- | --- |
| `AuraTable` — one class per table, `respond($request)` | Action columns: `edit` / `show` / `destroy` in convention mode (F5) |
| Columns, groups, footers, table settings | Per-row permissions (F5) |
| Column defaults inferred from the model's casts | `make:aura-table` and the demo app (F6) |
| The field whitelist, derived from the columns | |
| Sorting, searching, filtering, global search | |
| Relations in all four operations | |
| The nine cell renderers, with conditions and cell rules | |
| A cacheable, request-independent definition | |

Action columns can be built today — `Icon`, `Button` and `Modal` with a route are all it takes.
What F5 adds is the convention that names the routes for you, and the per-row permission machinery
that decides which rows get one.

The package is **not released**: no tag, not on Packagist. Install it from the repository.

---

## Requirements

- **PHP** 8.3 or 8.4
- **Laravel** 12 or 13 (`illuminate/support`, `illuminate/contracts`)
- A database driver Eloquent supports; the test suite runs on SQLite, and the `LIKE` escaping is
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

`AuraServiceProvider` is registered by package discovery — nothing to add to
`bootstrap/providers.php`.

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

---

## Defining a table

```php
<?php

namespace App\Tables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\TableSettings;

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
            Column::make('status')->filterable(),
            Column::make('balance')->sortable()->searchable(),
            Column::make('created_at')->sortable()->searchable(),
        ];
    }

    public function settings(): TableSettings
    {
        return TableSettings::make()->stickyHeader()->striped()->hoverable();
    }
}
```

```php
Route::post('/users', fn (Request $request) => (new UserTable)->respond($request));
```

That is the whole endpoint. Aura fetches with `POST` by default, so route it accordingly (or set
`requestMethod` on the client side).

`query()` is where constraints that are always true belong — tenant scoping, eager loads, a
`withTrashed()`. Everything the user chooses is applied on top of it.

### Why one class rather than a chain in the controller

Because the two halves of the contract are two readings of one definition. The header says
`sortable: true`; the query layer decides whether to accept a sort on that field. Written
separately they drift, and the failure is quiet in the worst direction — a column the header
offers but the whitelist forgot turns every click into a 422, and a whitelist entry with no
column is a field the client can operate on that the table never meant to expose.

Here the whitelist is *derived from the cells the browser receives*, resolving the field exactly
as Aura does (`reference || field || key`). A test walks every column against every operation and
asserts the two agree.

The second reason is caching: the header, body and footer do not depend on the request, so they
can be built once. A fluent chain in a controller rebuilds all of it on every fetch.

### What comes out

```php
(new UserTable)->respond($request);   // header + body + items + meta + links
(new UserTable)->definition();        // header + body + footer — the request-independent half
(new UserTable)->permissions();       // the FieldPermissions the columns imply
```

---

## Columns

A column is a header cell. `Column::make('last_name')` is a complete one: the heading defaults to
the field name in title case, and the key defaults to the field.

```php
Column::make('last_name')                    // heading "Last Name"
Column::make('last_name', 'Vezetéknév')      // explicit heading
Column::selection()                          // the row-selection checkboxes
Column::combined('full_name', ['first_name', 'last_name'], 'Name')
Column::heading('Account', colspan: 3)       // a heading over other columns
```

### Behaviour

| Method | Effect |
| --- | --- |
| `sortable()` | offer sorting, and allow it on the query side |
| `searchable()` | offer the per-column search input |
| `filterable()` | offer the per-column filter dropdown |
| `between()` | search by min–max range instead of a term |
| `globalSearch()` | include the field in the toolbar's global search |
| `reference('other_field')` | operate on a different field than the one rendered |
| `elements([...])` / `options(Enum::class)` | the filter dropdown's options |
| `selectable()` | render the row-selection checkboxes here |
| `show(false)` / `hidden()` | start hidden — presentation, never authorisation |

`reference()` is how a rendered column sorts by an underlying one: a full-name column sorts by
`last_name`. Aura resolves the field it sends as `reference || field || key`, and the whitelist
follows the same order, so the reference — not the rendered field — is what the query accepts.

`hidden()` is not a permission. A hidden column is one the user can switch back on; a column
nobody may see must not be in `columns()` at all.

### Layout and formatting

`width()`, `resizable()`, `colspan()`, `rowspan()`, `align()`, `class()`, `style()`,
`cellClass()`; and `number()`, `currency()`, `date()`, `datetime()`, `time()`, `phone()`,
`slice()`, `uppercase()`, `lowercase()`, `capitalize()`, `monospace()`, `raw()`.

`cellClass()` is the odd one out: it styles the column's **data** cells (`body.columnStyles`),
where `class()` styles the heading.

### Anything else

The contract defines more header-cell keys than there are methods here. `set()` and `merge()`
reach all of them, `data-*` attributes included:

```php
Column::make('note')
    ->set('data-testid', 'note-column')
    ->merge(['fontWeight' => 700, 'lineHeight' => 1.4]);
```

`Column` is `Macroable`, so a call you repeat can become a method of its own.

---

## Inference

The model already knows most of what a column needs. Restating it in the table definition is
duplication that goes stale — the cast changes and the column quietly keeps formatting the old
way. So the defaults are read from the model's casts:

| Cast | Default |
| --- | --- |
| `decimal:*` | `currency`, `align: end` |
| `datetime`, `immutable_datetime`, `timestamp` | `datetime`, plus `between` if the column is searchable |
| `date`, `immutable_date` | `date`, plus `between` if the column is searchable |
| a `BackedEnum` class | `elements` — the filter dropdown's options |
| the model's key name | the field of a `Column::selection()` |

Dotted fields are resolved one relation deep, so `company.tier` picks up the cast on the *company*
model.

Three properties, each with a test:

- **Inference only ever fills a gap.** It writes through the same door presets do, so an explicit
  call wins whichever order it was made in — `Column::make('balance')->align('center')` keeps
  `center` even though the decimal cast would have said `end`.
- **`->withoutInference()`** turns it off for one column, for the `decimal` that is a weight
  rather than a price.
- **It is best-effort.** A field it cannot resolve — a computed column, a relation two levels
  away — simply gets no defaults. Guessing wrong is worse than not guessing.

---

## Enums

A backed enum used as a cast becomes the filter dropdown's options. Implement `AuraOption` and the
labels are yours:

```php
use TamasLabs\Aura\Contracts\AuraOption;

enum Status: string implements AuraOption
{
    case Active = 'active';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::Active => __('Active'),
            self::Suspended => __('Suspended'),
        };
    }
}
```

```php
Column::make('status')->filterable();
// elements: { "active": "Active", "suspended": "Suspended" }
```

The keys are the backing values — what the database holds and what travels in the request — and
the labels are what the user reads. An enum that has *not* implemented the interface still
produces a usable list from its case names, so this buys wording, not behaviour.

For an enum the model does not cast to, ask for it directly: `->options(Status::class)`.

Options come from the enum's cases rather than from the loaded rows. That is the difference that
matters: options derived from the current page cannot offer a status nobody has yet.

Two more interfaces are available, both optional and both separate from `AuraOption`, because a
filter list needs a label and nothing else — requiring a colour from every enum that appears in
one would mean dead methods on most of them:

```php
enum Status: string implements AuraOption, AuraVariant, AuraIcon
{
    public function variant(): string { … }   // 'success' — or a key into the app's variants registry
    public function icon(): string { … }      // a key into the app's icons registry
}
```

`Badge::fromEnum(Status::class)` reads all three and builds the badge for each case. An enum that
implements none of them still produces a usable badge map, from its case names — what it cannot
produce is colours, and inventing one per case is worse than leaving it out.

---

## Presets

A preset is a reusable bundle of column settings — what stops the same four calls from being
repeated on every money column in the application.

```php
use TamasLabs\Aura\Table\Presets\{Money, Options, Timestamp};

Column::make('minor_units', 'Total')->apply(new Money);
Column::make('archived_at')->searchable()->apply(new Timestamp);
Column::make('born_on')->apply(Timestamp::date());
Column::make('plan')->apply(new Options(Tier::class));
```

| Preset | Sets |
| --- | --- |
| `Money` | `currency`, `align: end`, `monospace` |
| `Timestamp` | `datetime` (or `date`), plus `between` on a searchable column |
| `Options` | `filterable`, `elements` from an enum, `align: center` |

Presets write through the same door as inference, so an explicit call still wins in either order.
Write your own by implementing `Preset::apply(Column $column): void`.

---

## Cell rendering

By default a cell shows its value as text. A **cell configuration** replaces that with one of the
nine renderers the contract defines, and attaches to the column:

```php
use TamasLabs\Aura\Cell\{Badge, Icon, Modal, Reference};

public function columns(): array
{
    return [
        Column::make('status')->filterable()->as(Badge::fromEnum(Status::class)),
        Column::make('email')->as(Reference::make()->lowercase()),
        Column::make('edit')->content(null)->as(Icon::make('pencil')->route('users.{id}.edit')),
        Column::make('delete')->content(null)->as(Modal::destroy()->route('users.{id}.destroy')->icon('trash', 'danger')),
    ];
}
```

The configuration is a separate object from the column on purpose. The column describes the
**heading** and what the server will let the client do with the field; the configuration
describes the **cell**. They share key names (`align`, `currency`, `class`) with different
destinations, and one builder for both would make which you were setting a matter of guesswork.

Two things are worth knowing before you attach one.

**The heading's formatting stops reaching the cell.** Aura hands the renderer the configuration on
its own, so a `currency()` column with a plain configuration would suddenly show raw figures. The
column's formatter settings are therefore copied into the configuration as defaults — an explicit
call on the configuration still wins:

```php
Column::make('balance')->as(Reference::make());              // still formatted as currency
Column::make('balance')->as(Reference::make()->currency(false));  // deliberately not
```

**A column that carries a configuration must have the same key and field.** Aura looks the
renderer up under `columnConfigs[column.field]` and the cell rules under
`columnConfigs[column.key]` — a column where the two differ would need both entries and would get
whichever the lookup happened to reach. That is the default anyway; an explicit `->key()` that
disagrees is refused rather than half-applied.

### The nine types

| Type | Builder | Renders |
| --- | --- | --- |
| `static` | `Text::make('—')` | fixed text — **not** the row's value |
| `reference` | `Reference::make()` | the row's value, through the formatter chain |
| `badge` | `Badge::make()` | a coloured pill |
| `link` | `Link::make()->route(…)` | an anchor |
| `button` | `Button::make('Edit')->route(…)` | a button |
| `icon` | `Icon::make('pencil')` | a glyph, optionally linking |
| `modal` | `Modal::destroy()` | a trigger that opens a modal |
| `progress` | `Progress::make()` | a progress bar |
| `custom` | `Custom::template(…)` | whatever the host app's registry renders |

`Text` is the contract's `static` type; `Static` is a reserved word in PHP. It renders `value` and
never reads the row — for the row's own data with the same formatting, use `Reference`, whose
`field` defaults to the column's.

Every type shares the formatter chain (`currency`, `date`, `datetime`, `time`, `slice`,
`uppercase`, `padStart`, `raw`, …) and the typography block (`color`, `align`, `fontSize`,
`italic`, …) where the contract gives it one, plus `class()` and `style()` on the element it
draws. `Icon`, `Modal` and `Progress` have no formatter chain, and inherit nothing from the column.

`datetime`, `time` and `raw` are missing from the config schemas but read by the renderer all the
same (`buildFormatConfig.ts`), and every config allows additional properties — so they are offered
here beside the documented ones. Three more the renderer reads and no builder covers —
`currencyCode`, `sliceEnd`, `pad` — are reachable through `merge()`.

`Custom` is deliberately thin. Its `renderer` and `callback` name functions in the host app's
JavaScript registry, and PHP cannot check that either exists — a typo is a cell that renders
nothing, found in the browser. The coupling runs the other way from everything else here: the name
written in PHP is a promise about the front-end build.

For a key no builder covers, `merge()` is the escape hatch — unvalidated, and honest about it:
every column config in the schema declares `additionalProperties: true` and requires only `type`,
so running it past the schema would wave almost anything through. Only the structural keys
(`type`, `key`, `if`, `else`) are refused, because those decide how the rest is read — and they
are refused in `set()` itself, which is where both `merge()` and a direct call end up. A
hand-written `key` would otherwise win over the one the conditions are emitted with, and take the
conditions with it.

### Multi-field columns

A `combined()` column renders one segment per member field, and Aura looks each one up by that
field's name. So it is configured a field at a time:

```php
Column::combined('name', ['first_name', 'last_name'])
    ->reference('last_name')
    ->sortable()
    ->configure('last_name', Reference::make()->uppercase());
```

A single `->as()` on such a column has nowhere to attach, and is refused.

### Conditions

Any configuration can vary per row. Branches are evaluated in order and the first match wins:

```php
use TamasLabs\Aura\Cell\Condition;

Column::make('balance')->as(
    Reference::make()
        ->when(Condition::lt(0), fn (Reference $r) => $r->color('danger')->fontWeight(700))
        ->when(Condition::gt(1_000_000), fn (Reference $r) => $r->color('success'))
        ->otherwise(fn (Reference $r) => $r->color('dark'))
);
```

`when()` takes the condition and a callback that configures that branch; `otherwise()` is what
applies when none matched. **Leaving `otherwise()` out is meaningful**: a row matching no branch
renders an empty cell, and that is the supported way to hide a cell per row.

The conditions read the column's own field unless `on()` names another:

```php
Badge::make()->on('archived_at')->when(Condition::notNull(), fn (Badge $b) => $b->variant('secondary'));
```

Nineteen operators, which is every one the contract has once its five aliases are removed:

| | | |
| --- | --- | --- |
| `eq($v)` `ne($v)` | `gt($v)` `gte($v)` `lt($v)` `lte($v)` | `between($min, $max)` |
| `in($values)` `notIn($values)` | `contains($s)` `startsWith($s)` `endsWith($s)` | `regex($pattern)` |
| `isNull()` `notNull()` | `isEmpty()` `notEmpty()` | `isTrue()` `isFalse()` |

Four of these behave in ways the contract's own descriptions get wrong, and the differences bite:

- **`isTrue()` / `isFalse()` are exact.** `1` is not `true`. A `tinyint` column has to be cast to
  `bool` on the model, or the branch is always false.
- **`isEmpty()` counts `0` and `false` as empty**, and does *not* count `[]` or `{}`.
- **`eq()` is strict.** `1` does not match `'1'`.
- **`regex()` takes a JavaScript pattern** — no delimiters, no PHP modifiers. A pattern that fails
  to compile in the browser makes the branch false, silently.

Nesting deeper than five levels throws: Aura resolves five and silently renders the truncated
configuration.

### Numeric comparisons

`gt`, `gte`, `lt`, `lte` and `between` require a real number on both sides — or two values that
both parse as dates — and are **false otherwise, with nothing logged anywhere**. Laravel's
`decimal:2` cast serialises as a string:

```json
{ "balance": "1234.50" }
```

So `Condition::gt(1000)` on a money column would never match, on exactly the sort of column that
most wants such a condition. The package therefore collects the fields its conditions compare
numerically and converts those — and only those — in the response. Two limits are deliberate: only
numeric strings are touched, so a date compared with `gt` survives intact; and only fields a
condition actually reads, so the rest of the payload is unchanged.

A `Progress` bar's `field`, `min` and `max` are converted for the same reason, condition or not.

### Cell and row rules

`CellRules` styles the `<td>` around the content, with the same conditional interface:

```php
use TamasLabs\Aura\Cell\CellRules;

Column::make('balance')->rules(
    CellRules::make()->when(Condition::lt(0), fn (CellRules $r) => $r->background('#fee'))
);
```

The same object styles whole rows, from the table:

```php
public function rowRules(): ?CellRules
{
    return CellRules::make()
        ->on('status')
        ->when(Condition::eq('suspended'), fn (CellRules $r) => $r->opacity(0.5));
}
```

Row rules have no column to borrow a field from, so they have to name one with `on()`.

**Row rules are formatting only.** They cannot hide a row, and styling one away leaves its data in
the payload for anyone reading the response. A row the user must not see belongs outside
`query()`.

### Routes

A route is a template resolved per row. Aura substitutes the `{placeholder}` tokens, **replaces
every remaining dot with a slash**, and prefixes the host app's `siteName`:

```php
Icon::make('pencil')->route('users.{id}.edit');   // → /users/5/edit
Icon::make('pencil')->route('/users/{id}/edit');  // → the same
```

Which is why the absolute URL Laravel's `route()` helper returns is refused: it would come out as
`/https://app/example/com/users/5/edit`. A placeholder outside Aura's `[\w.]+` alphabet is refused
too — it would survive into the URL as literal text. What the package cannot check is the value: a
placeholder filled with something containing a dot (an email address, say) makes a mess of the
path.

### Bootstrap in the contract

`class`, `text` and the Bootstrap colour names (`primary`, `success`, `danger`, …) are contract
keys, not an abstraction this package invented. They travel over the wire as they are and mean
nothing to a differently styled front end. `variant` and `icon` are a step removed — they are keys
into the host app's own `variants` and `icons` registries, which Aura resolves into classes on its
side, so a raw CSS class passed as an icon name renders nothing.

---

## Grouped headers

```php
use TamasLabs\Aura\Table\ColumnGroup;

public function columns(): array
{
    return [
        Column::selection(),
        ColumnGroup::make('User', [Column::make('first_name'), Column::make('last_name')]),
        ColumnGroup::make('Account', [Column::make('status'), Column::make('balance')]),
    ];
}
```

Declaring a group makes the header two rows deep. The group cell spans its children; the children
keep their own cells in the second row.

**Every data column ends up in the last row**, and this is not a stylistic choice. Aura derives
the columns of the body from `header.rows[last]` alone. A column parked in the first row with a
`rowspan` would render a heading and no data — the body would draw one `<td>` fewer than the
header has `<th>`s — and a `selectable` cell stranded there disables row selection without saying
so. An ungrouped column therefore gets an empty placeholder cell above it rather than a rowspan.

A group of one column is refused: the contract requires a cell that names no field to span at
least two, and a group of one is a column with a heading.

---

## Footers and settings

```php
use TamasLabs\Aura\Table\Footer;

public function footer(): ?Footer
{
    return Footer::make(
        Column::heading('Total', colspan: 3),
        Column::make('balance_total')->align('end'),
    );
}
```

A footer is built from the same cells as the header, and validated by the very same schema — which
includes the rule that a cell naming no field must span at least two columns.

```php
public function settings(): TableSettings
{
    return TableSettings::make()
        ->stickyHeader()->headerHeight('48px')
        ->striped()->hoverable()
        ->stickyFooter();
}
```

The contract scatters these across `header.settings`, `body.settings` and `footer.settings`.
`TableSettings` collects them and does the splitting on the way out; a block nobody set is omitted
entirely.

---

## Caching the definition

The header, body and footer do not depend on the request. Opt in and they are built once:

```php
final class UserTable extends AuraTable
{
    protected bool $cache = true;
    protected int $cacheTtl = 3600;
}
```

```php
(new UserTable)->forgetCache();   // after a deploy that changes the columns
```

The definition **and** the whitelist are cached together, because they are two readings of one
definition; caching one without the other is how a header comes to offer a sort the server
refuses. Override `cacheKey()` when one table class serves several shapes — per locale, say.

It is off by default because it is only safe once `columns()` is genuinely request-independent. A
definition that reads the current user, the locale or a feature flag will be cached for whoever
asked first.

The cache is treated as untrusted on the way back in: an entry that is not the array we wrote
triggers a rebuild, and anything that is not a string is dropped from the field lists. A stale or
tampered entry cannot widen a whitelist.

---

## What the table refuses to build

These are `InvalidDefinition` — a `LogicException`, because nothing a user does can cause one.
Each reports a mistake in the table class, on the first request that touches it, rather than
letting the browser render the wrong thing:

| Refused | Why |
| --- | --- |
| Two columns sharing a key | the key identifies the column in `columnConfigs`, `columnStyles` and Aura's per-column session state |
| A `combined()` column that is sortable or searchable with no `reference` | Aura would fall back to the key, which is a name the database has never heard of |
| A `combined()` column in the global search | `searchableItems` names the `field` of a header cell, and this column has none |
| A group spanning fewer than two columns | a field-less cell must span at least two |
| `Column::heading()` with `colspan: 1` | same rule |
| A table with no columns | the contract requires at least one header row with at least one cell |
| An empty-string heading | the contract wants a non-empty string or `null`; an empty one fails Aura's own validation and takes the whole table down |
| A cell configuration on a column whose key and field differ | the renderer is read under the field and the cell rules under the key, so only half of it would be used |
| A single configuration on a `combined()` column | Aura renders one segment per member field and looks each up by name — use `configure()` |
| `configure()` naming a field the column does not read | there is no segment for it |
| A configuration that renders nothing (`Text` with no value, `Icon` with no glyph, `Modal` with no trigger) | the cell would come out empty |
| Conditions nested more than five deep | Aura resolves five and silently renders the truncated configuration |
| Conditions with no field to read | without a `key` Aura skips them and applies the base configuration — fail-open |
| An absolute route, or a placeholder outside `[\w.]+` | Aura turns every dot into a slash, so `route()`'s URL becomes a path; an unmatched placeholder stays in the URL verbatim |
| Two columns rendering the same field | `columnConfigs` is one flat map keyed by field, so the second entry replaces the first and the losing column renders the winner's configuration |
| `merge()` or `set()` naming `type`, `key`, `if` or `else` | those decide how everything else is read; a hand-written `key` beats the emitted one and takes the conditions with it |

---

## The query layer on its own

`AuraTable` is built on three pieces you can also use directly — for an endpoint whose shape does
not come from a table class at all.

```php
use TamasLabs\Aura\Query\{AuraQuery, FieldPermissions};
use TamasLabs\Aura\Request\AuraRequest;
use TamasLabs\Aura\Response\AuraPayload;

$aura = AuraRequest::fromHttp($request, new FieldPermissions(
    sortable:     ['last_name', 'created_at'],
    searchable:   ['first_name', 'last_name'],
    filterable:   ['status'],
    globalSearch: ['first_name', 'last_name'],
));

$payload = AuraPayload::fromPaginator(AuraQuery::paginate(User::query(), $aura));

return ['header' => $handWrittenHeader] + $payload->toArray();
```

Using it this way means keeping the header and the whitelist in agreement by hand, which is
exactly what `AuraTable` exists to stop.

### FieldPermissions

Every `field` in an Aura request comes from the browser. Passing one straight to `orderBy()` leaks
the existence — and, through sorting, the ordering — of columns the table never meant to expose.
`FieldPermissions` is the whitelist, and it is the **only** way a client-supplied field reaches
the query. A table class builds it from its columns; build it by hand only here.

Four properties hold, and each has a test pinning it:

- **The lists are separate.** A field being searchable does not make it sortable.
- **An empty list allows nothing.** There is no "allow everything" switch, and
  `FieldPermissions::none()` is the safe starting point.
- **Matching is exact.** `last` is not allowed by `last_name`.
- **A rejected field is a 422**, never a silently ignored parameter. The error names the field
  that was refused but never lists the permitted ones — an error response is not a place to
  enumerate the schema.

### AuraRequest

```php
AuraRequest::fromHttp(Request $request, FieldPermissions $fields, ?int $maxPaginate = null): self
AuraRequest::fromArray(array $payload, FieldPermissions $fields, ?int $maxPaginate = null): self
```

`fromHttp` reads the payload from where the contract puts it: the JSON body on `POST` / `PUT` /
`PATCH`, the query parameters on `GET` / `DELETE`. Both throw `ValidationException` — a **422** —
on anything the contract does not allow.

| Key | Type | Required | Notes |
| --- | --- | --- | --- |
| `page` | integer ≥ 1 | **yes** | |
| `paginate` | integer ≥ 1 | **yes** | clamped to `pagination.max` |
| `sortable[]` | `{field, direction}` | no | `direction` is `asc` or `desc` |
| `searchable[]` | `{field, term?, exact?, min?, max?}` | no | term search or range search |
| `filterable[]` | `{field, values[]}` | no | `values` may be empty, but must be present |
| `globalSearch` | string | no | |
| `selected[]` | string / number | no | row ids for bulk actions |

Unknown properties are rejected — at the top level *and* inside the nested objects. The nested
check runs on the raw payload on purpose: Laravel's validator drops every key it has no rule for,
so by the time validation is done an unknown nested key has already vanished and could never be
reported.

**`selected` never touches the query.** It names the rows the user has ticked, for the caller's
own bulk actions; a test pins that the generated SQL is identical with and without it. Filtering
the page down to the selection would be wrong twice over: the selection can span pages, and the
user did not ask for it.

```php
User::whereKey($aura->selected)->each->archive();
```

### AuraQuery

```php
AuraQuery::apply(Builder $query, AuraRequest $request): Builder
AuraQuery::paginate(Builder $query, AuraRequest $request): LengthAwarePaginator
```

**Searching.** A `searchable[]` entry is either a term search or a range search:

| Sent | SQL |
| --- | --- |
| `{field, term}` | `LIKE '%term%'` — substring, wildcards escaped |
| `{field, term, exact: true}` | `= 'term'` |
| `{field, min, max}` | `>= min` and `<= max` |
| `{field, min}` or `{field, max}` | one open end |

`null` at either end of a range means *unbounded*, not *match null*. An empty or missing term adds
no constraint at all rather than matching everything.

**Wildcards in the term are escaped.** Without that, a term of `%` matches every row — not an
injection (the term is still bound), but a search box that quietly turns into a full table scan,
and a search for `100%` that also returns `1000`. The escape character is `!`, not a backslash:
MySQL and SQLite disagree on whether a backslash inside a string literal is itself an escape.
`AuraQuery::likeExpression()` is the only raw SQL in the package and carries the reasoning.

**Filtering.** `{field, values}` matches a row whose column equals any of the values. A `null`
among them adds `OR column IS NULL` — `IN (…)` never matches `NULL`, so a selected "no value"
would otherwise silently drop exactly the rows the user asked for. An empty `values` array matches
nothing, which is what an empty selection means.

**Global search.** One term, `OR`-ed across the declared fields, wrapped in its own nested `where`
so the ORs cannot escape and widen the per-column constraints around them.

**Relations.** A dotted field resolves through the relation it names:

| Operation | Mechanism | Depth | Relation types |
| --- | --- | --- | --- |
| Search | `whereHas` | any | any |
| Filter | `whereHas` | any | any |
| Global search | `orWhereHas` | any | any |
| **Sort** | **correlated subquery** | **one level** | **`BelongsTo`, `HasOne`** |

Sorting is the restricted one, deliberately. A join would read more naturally, but it multiplies
rows on a to-many relation — which corrupts `meta.total` and the contents of every page, breaking
pagination itself. A correlated subquery has no such effect; the price is that it only answers for
a to-one relation, one level deep. Anything else raises `UnsupportedRelation` with a concrete
suggestion.

### AuraPayload

```php
AuraPayload::fromPaginator(Paginator|CursorPaginator $paginator): self
$payload->toArray(): array   // ['items' => …, 'meta' => …, 'links' => …]
```

`items` are the rows flattened to plain data, re-indexed with `array_values` — a paginator page
with gaps in its keys would serialise to a JSON object instead of an array.

**Only `LengthAwarePaginator` works.** The contract requires `meta.last_page` and `meta.total`,
and neither `simplePaginate()` nor `cursorPaginate()` knows them, because neither runs the count
query. Passing one raises `UnsupportedPaginator` rather than emitting a payload the table cannot
read.

---

## Exceptions

Every exception this package raises on its own behalf implements
`TamasLabs\Aura\Exceptions\AuraException`, so a host application can catch the lot at once:

| Exception | Raised when |
| --- | --- |
| `InvalidDefinition` | the table definition itself is wrong — see [the table above](#what-the-table-refuses-to-build) |
| `UnsupportedRelation` | sorting through a to-many relation, or a nested relation path |
| `UnsupportedPaginator` | the paginator cannot report `last_page` / `total` |

These all report a mistake in the **table definition**, never in the client's input — malformed
input fails validation and becomes a 422 long before it reaches them.

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
| **F3** | Definition core: `AuraTable`, `Column`, inference, caching | ✅ done |
| **F4** | Cell builders: badge, link, button, icon, modal, progress, conditional configuration | ✅ done |
| **F5** | Action layer (`edit` / `show` / `destroy`) and per-row permissions | planned |
| **F6** | Demo workbench app, `make:aura-table`, release | planned |

---

## License

MIT

## Author

Tamas Balint
