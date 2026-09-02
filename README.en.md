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
  - [`limits` — the rest of the payload](#limits--the-rest-of-the-payload)
- [Defining a table](#defining-a-table)
  - [Generating one](#generating-one)
- [Columns](#columns)
- [Inference](#inference)
- [Enums](#enums)
- [Presets](#presets)
- [Cell rendering](#cell-rendering)
  - [The nine types](#the-nine-types)
  - [Every builder method](#every-builder-method)
  - [Multi-field columns](#multi-field-columns)
  - [Conditions](#conditions)
  - [Numeric comparisons](#numeric-comparisons)
  - [Cell and row rules](#cell-and-row-rules)
  - [Routes](#routes)
  - [Bootstrap in the contract](#bootstrap-in-the-contract)
- [Action columns](#action-columns)
  - [The key is a placeholder, not a name](#the-key-is-a-placeholder-not-a-name)
  - [Escalation](#escalation)
  - [Routes](#action-routes)
- [Per-row permissions](#per-row-permissions)
  - [How it is emitted](#how-it-is-emitted)
  - [One query for the page, not one per row](#one-query-for-the-page-not-one-per-row)
  - [Caching](#caching)
- [Grouped headers](#grouped-headers)
- [Footers and settings](#footers-and-settings)
- [Caching the definition](#caching-the-definition)
- [What the table refuses to build](#what-the-table-refuses-to-build)
- [The query layer on its own](#the-query-layer-on-its-own)
  - [FieldPermissions](#fieldpermissions)
  - [AuraRequest](#aurarequest)
  - [What bounds a request](#what-bounds-a-request)
  - [AuraQuery](#auraquery)
  - [AuraPayload](#aurapayload)
- [Exceptions](#exceptions)
- [The wire contract](#the-wire-contract)
- [Versioning](#versioning)
  - [What semver covers](#what-semver-covers)
  - [What the contract's own version covers](#what-the-contracts-own-version-covers)
  - [Before the tag](#before-the-tag)
- [Validating your own payloads](#validating-your-own-payloads)
- [Development](#development)
  - [The demo application](#the-demo-application)
- [Roadmap](#roadmap)
- [License](#license)

---

## Status

The package is at **F6.4** of its plan: a table is a class, it serves a request end to end, its
cells render as more than text, the four resource actions are one call — customised or not — a
cell can be offered to some rows and not others, `make:aura-table` writes the first draft, and the
reference documentation — and the versioning promise under it — is held up by a test rather than
by discipline.

| Works today | Not built yet |
| --- | --- |
| `AuraTable` — one class per table, `respond($request)` | The Packagist release (F6.5) |
| Columns, groups, footers, table settings | |
| Column defaults inferred from the model's casts | |
| The field whitelist, derived from the columns | |
| Sorting, searching, filtering, global search | |
| Relations in all four operations | |
| The nine cell renderers, with conditions and cell rules | |
| Action columns — convention mode, and escalation to a full configuration | |
| Routes from `$resource`, from a named route, or spelled out | |
| Per-row permissions — `allowedWhen()`, batched or not | |
| A cacheable, request-independent definition | |
| `make:aura-table`, scaffolded from the model's table | |
| A runnable demo app (`composer serve`) | |
| A documentation-coverage guard over both references | |
| A stated, tested versioning promise | |

What is left is the release itself: the tag.

The package is **not released**: no tag, not on Packagist. Install it from the repository.

---

## Requirements

- **PHP** `^8.3` — the CI matrix tests 8.3 and 8.4; the constraint allows 8.5, which is not tested yet
- **Laravel** `^12.0 || ^13.0` — the `illuminate/*` components, not the framework package
- A database driver Eloquent supports; the test suite runs on SQLite, and the `LIKE` escaping is
  written to behave identically on MySQL/MariaDB, PostgreSQL and SQLite
- On the browser side, an Aura that reads **contract 1.0** — see [Versioning](#versioning), which is
  the version number that actually decides compatibility

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

```php
return [
    'pagination' => [
        'max' => 100,          // ceiling on the client's `paginate` — clamped
    ],

    'limits' => [
        'selected' => 1000,    // ids in `selected`
        'values' => 200,       // values in one filter dropdown
        'term' => 255,         // characters in `globalSearch` and `searchable[].term`
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

### `limits` — the rest of the payload

`paginate` is not the only attacker-controlled number in a request. **`limits`** bounds the three
things nothing else can:

| Key | Default | Bounds |
| --- | --- | --- |
| `limits.selected` | 1000 | ids in `selected[]` |
| `limits.values` | 200 | values in one `filterable[].values` |
| `limits.term` | 255 | characters in `globalSearch` and in a `searchable[].term` |

Exceeding one of these is a **422, not a clamp**. The `paginate` argument for clamping is that a
stale client keeps working; nothing legitimate produces a 200 000-character search term, so there
is no working client to keep.

**Notice what is *not* here.** The `sortable`, `searchable` and `filterable` lists have no key,
because they need none — see [What bounds a request](#what-bounds-a-request).

`values` is a config value rather than the column's own `elements` because `elements` is optional:
a column that declares none lets Aura build the filter options from the loaded rows, so there is
nothing on the server to derive a ceiling from.

A missing or non-positive configured value falls back to the packaged default rather than to "no
limit" — a limit a broken config can switch off is not a limit.

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

<a id="generating-one"></a>
### Generating one

```bash
php artisan make:aura-table UserTable --model=User
```

It writes `app/Tables/UserTable.php`, scaffolded from the model's **own database table**: one
`Column::make()` per column, with the flags its type and cast justify.

| What it finds | What it writes |
| --- | --- |
| a `BackedEnum` cast, or a boolean | `->filterable()` — the cast fills the dropdown's `elements` at build time |
| anything else readable — text, numbers, dates | `->sortable()->searchable()`; `currency`, the alignment and the range input arrive from the cast when the definition is built |
| the primary key | nothing — the selection and action columns already read it |
| a `*_id` foreign key | a comment pointing at `Column::make('company.name')`, which renders the related row and sorts it with a subquery |
| a `json` / `blob` column, or anything in the model's `$hidden` | a comment, or nothing |

Two lines are there because every table needs them and one of them is a trap:
`Column::selection()->key('select')` and `Column::actions('id', …)`. Both default to the model's
key, and the action column is the one that cannot move — its key *is* the route placeholder — so
the generator re-keys the selection column, which is free (Aura reads the row id from that column's
`field`).

**It is a first draft, and it declines to guess anything editorial.** Nothing is put in the global
search, nothing is given a cell renderer, and no `$resource` is set; the generated class docblock
says so. Without a reachable database it writes a placeholder and tells you.

`--model` is optional — the model is guessed from the class name the way `make:policy` guesses,
minus a trailing `Table`. A model outside the application namespace is taken as it stands. Drop a
`stubs/aura-table.stub` in the project root to replace the template.

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
Column::actions('id', Action::edit())        // the resource links — see Action columns
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

### Every builder method

Most of what a configuration can do comes from four shared blocks; each type then adds a handful of
its own. What follows is the complete public surface of the cell layer — every other method on
these classes is marked `@internal` and is not covered by the version promise.

**On every configuration.**

| Method | Does |
| --- | --- |
| `when(Condition $c, callable $branch)` | a branch, merged over the base when the condition holds; the first match wins |
| `otherwise(callable $branch)` | what applies when nothing matched — leaving it out is how a cell is hidden |
| `on(string $field)` | the field the conditions read; defaults to the one the configuration is attached to |
| `rules(CellRules $rules)` | conditional styling of the `<td>` the content sits in |
| `allowedWhen(callable $allowed)` | render only for the rows the callback allows |
| `allowedWhenAll(callable $resolver)` | the same, prepared once for the whole page |
| `set(string $key, mixed $value)` | one contract key, explicitly |
| `merge(array $attributes)` | several at once — the unvalidated escape hatch |
| `class(string\|array $class)`, `style(string $style)` | CSS on the element the type draws |

**The formatter chain** — on `Text`, `Reference`, `Badge`, `Link`, `Button` and `Custom`. `Icon`,
`Modal` and `Progress` have none, and inherit no formatting from their column either.

| Method | Does |
| --- | --- |
| `number()` | the host app's number format |
| `currency()` | the host app's `currencyCode` |
| `date()`, `datetime()` | a date, or a date with time |
| `time()` | seconds, rendered `HH:mm:ss` |
| `phone()` | a phone number |
| `raw()` | render as HTML — Aura sanitises it first, but the shortest safe answer is still not to send markup you did not build |
| `unit(string $unit)` | a unit appended to the value: `kg`, `%` |
| `slice(int $characters)` | truncate the rendered text |
| `uppercase()`, `lowercase()`, `capitalize()` | case |
| `monospace()` | monospace figures; pairs with `align('end')` |
| `padStart(int $length, ?string $chars = null)`, `padEnd(…)` | pad to a width |
| `chars(string $chars)` | the padding character, when it is not passed to `padStart()` directly |

**Typography** — on everything except `Icon` and `Modal`.

| Method | Does |
| --- | --- |
| `color(string $color)` | text colour: a Bootstrap theme colour name, or CSS colour syntax |
| `background(string $color)` | colour behind the content |
| `align(string $align)` | alignment inside the cell |
| `fontSize(string $size)` | a `px`/`rem`/`em`/`%` length, or a keyword such as `large` |
| `fontWeight(int\|string $weight)` | a multiple of 100 from 100 to 900, or a keyword |
| `italic()`, `normal()` | italics, and undoing them in a branch that has to |
| `lineHeight(float\|int\|string $lineHeight)` | a positive number, a length, or `normal` |
| `text(string $utility)` | a Bootstrap `text-*` utility class — framework-bound by nature, and meaningless to a differently styled front end |

**Mapping** — on `Reference`, `Badge`, `Link`, `Button`, `Icon` and `Custom`: `mapping(array
$mapping)` is a lookup table keyed by the field's value, each entry a set of settings for that
value. `Progress` has one too, with its own semantics — the keys are ranges (`"0-25"`), not values.

**Routes** — on `Link`, `Button`, `Icon` and `Modal`: `route(string $route)`, described under
[Routes](#routes).

And what each type adds:

**`Text`** — the contract's `static` type.

| Method | Does |
| --- | --- |
| `Text::make(?string $value = null)` | the builder |
| `value(string $value)` | the text to render; it never reads the row |

**`Reference`** — the row's own value.

| Method | Does |
| --- | --- |
| `Reference::make(?string $field = null)` | the builder; the field defaults to the column's |
| `Reference::combined(array $fields, string $separator = ' ')` | several fields, rendered joined |
| `value(string $value)` | fixed text, ignoring the row — it wins over `fields` and `field` |
| `separator(string $separator)` | what goes between joined `fields` values |

**`Badge`**

| Method | Does |
| --- | --- |
| `Badge::make(?string $field = null)` | the builder |
| `Badge::fromEnum(string $enum, ?string $field = null)` | a badge per enum case: label, plus colour and icon where the enum offers them |
| `value(string $value)` | fixed label text, instead of the field's value |
| `variant(string $variant)` | base colour, rendered `text-bg-{variant}` |
| `pill(bool $pill = true)` | render as a pill |
| `size(string $size)` | `xs`, `sm`, `md`, `lg` or `xl` |
| `icon(string $icon, ?string $position = null)` | a glyph beside the label — a key into the host app's `icons` registry, not a CSS class |
| `whenTrue(array $badge)`, `whenFalse(array $badge)` | the badge shown for a truthy and a falsy value |
| `showZero(bool $show = true)` | render the badge when the number is `0`; on by default |
| `maxValue(int $max, string $suffix = '+')` | cap the number; past it the badge reads `{max}{suffix}` |
| `prefix(string $prefix)` | text prepended to the label, `#` say |

**`Link`**

| Method | Does |
| --- | --- |
| `Link::make(?string $field = null)` | the builder |
| `value(string $value)` | fixed link text, instead of the field's value |
| `variant(string $variant)` | colour: a key into the host app's `variants` registry, or a Bootstrap colour name |
| `title(string $title)` | tooltip text |
| `target(string $target)` | anchor `target` — `_blank` also sets a safe `rel`, since the opened page would otherwise get a handle on this one |
| `rel(string $rel)` | anchor `rel` |

**`Button`**

| Method | Does |
| --- | --- |
| `Button::make(?string $label = null)` | the builder |
| `value(string $value)` | fixed button label |
| `variant(string $variant)` | colour: a `variants` key, or a Bootstrap colour name |
| `size(string $size)` | `xs`…`xl` |
| `rounded(bool $rounded = true)`, `pill(bool $pill = true)` | corner shape |
| `icon(string $icon, ?string $position = null)` | a glyph — an `icons` registry key |
| `disabled(bool $disabled = true)` | render it disabled; presentation only, the row still carries its data |
| `title(string $title)` | tooltip text |
| `htmlType(string $type)` | the element's `type` attribute: `button`, `submit` or `reset` |

**`Icon`** — no formatter chain and no typography.

| Method | Does |
| --- | --- |
| `Icon::make(?string $icon = null)` | the builder; the glyph is an `icons` registry key |
| `variant(string $variant)`, `color(string $color)` | colour, resolved the same way |
| `size(string $size)` | `xs`…`xl` |
| `alt(string $alt)` | accessible label, rendered as `aria-label` — worth setting: a glyph on its own says nothing to a screen reader |
| `title(string $title)` | tooltip text |

**`Modal`** — a trigger, plus the id of the modal it opens.

| Method | Does |
| --- | --- |
| `Modal::make(string $id)` | the builder |
| `Modal::destroy()` | Aura's built-in delete confirmation |
| `icon(string $icon, ?string $variant = null)` | icon-trigger shorthand |
| `button(string $variant, ?string $label = null)` | button-trigger shorthand |
| `content(CellConfig $content)` | a trigger built from any other cell configuration |
| `size(string $size)` | trigger size |
| `alt(string $alt)`, `title(string $title)` | accessible label and tooltip on the trigger |
| `target(string $target)` | anchor `target`, for a link trigger |

**`Progress`**

| Method | Does |
| --- | --- |
| `Progress::make(?string $field = null)` | the builder |
| `Progress::stacked(array $bars)` | one bar from several segments, each reading its own field |
| `value(float\|int $value)` | a fixed value, instead of reading one from the row |
| `max(float\|int\|string $max)`, `min(…)` | the scale; a number, or the name of the field holding it. Defaults are `100` and `0` |
| `variant(string $variant)` | bar colour |
| `height(string $height)` | track height, `20px` say |
| `striped(bool $striped = true, bool $animated = false)` | striped, optionally animated |
| `label(bool\|string $label = true, ?string $position = null)` | `true` for the value itself, or fixed text |
| `showValue(bool $show = true)` | show the raw value in the label |
| `showPercent(bool $show = true, ?int $decimals = null)` | show the percentage, to this many decimals |
| `affixes(?string $prefix = null, ?string $suffix = null)` | text wrapped around the label |
| `thresholds(array $thresholds)` | Bootstrap colour → inclusive `[min, max]`; the first range holding the value colours the bar |
| `mapping(array $mapping)` | range-keyed (`"0-25"`) bar settings |

**`Custom`** — the one type whose contents PHP cannot check.

| Method | Does |
| --- | --- |
| `Custom::template(string $template)` | a template string; `{placeholder}` tokens come from the row and from `params()` |
| `Custom::renderer(string $name)` | a function in the host app's `renderers` registry, returning a node |
| `Custom::callback(string $name)` | a function in its `callbacks` registry, returning text |
| `field(string $field)` | the item field holding the value |
| `fields(array $fields)` | several item fields, passed to the renderer |
| `value(string $value)` | fixed text |
| `params(array $params)` | extra values available to the template and to the registered functions |

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

`CellRules` takes the same `when()` / `otherwise()` / `on()` / `set()` / `merge()` calls every
configuration does, and adds the styling itself:

| Method | Does |
| --- | --- |
| `CellRules::make()` | an empty rule set, ready to be given branches |
| `background(string $color)`, `color(string $color)` | cell background and text colour |
| `borderTop()`, `borderBottom()`, `borderLeft()`, `borderRight()` | draw a border on that side; each takes `bool $border = true` |
| `borderColor(string $color)`, `borderWidth(string $width)` | border colour and width, `3px` say |
| `padding(string $padding)` | inner padding, `8px 16px` say |
| `opacity(float $opacity)` | between 0 and 1 |

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

**An icon needs a `key` before Aura will make it a link.** `renderIconNode` wraps the glyph in an
`<a>` only when `route` *and* `key` are both present — `link`, `button` and `modal` need the route
alone. The package emits it for you, named after the route's first placeholder (`id` for
`users.{id}.edit`), falling back to the column's key for a route with none, exactly as Aura's own
preprocessor does. When a mapping is also present the key stays the mapping's selector: that is
the only role in which its *value* is read, and the link only needs one to exist.

That key cannot live at the root of a **conditional** configuration, where `key` names the field
the conditions read and Aura strips it before the renderer runs (`stripLogicProps`). So each
branch gets its own, decided against the settings that branch resolves with — the route may sit in
the branch and not in the base. Without this a per-row condition over a linking icon would hide
the cell correctly and then render every allowed row without its link:

```php
Icon::make('pencil')->route('users.{id}.edit')->on('can_edit')
    ->when(Condition::isTrue(), fn (Icon $i) => $i);

// {"type":"icon","icon":"pencil","route":"users.{id}.edit",
//  "key":"can_edit","if":[{"true":true,"key":"id"}]}
//                                      ^ the route key, where Aura keeps it
```

A branch given its own `on()` keeps it, and a branch that has conditions of its own is not a leaf
— its `key` is the selector for the level below, and the search carries on downwards.

### Bootstrap in the contract

`class`, `text` and the Bootstrap colour names (`primary`, `success`, `danger`, …) are contract
keys, not an abstraction this package invented. They travel over the wire as they are and mean
nothing to a differently styled front end. `variant` and `icon` are a step removed — they are keys
into the host app's own `variants` and `icons` registries, which Aura resolves into classes on its
side, so a raw CSS class passed as an icon name renders nothing.

---

## Action columns

Aura builds the resource links itself. A header cell naming a field called `edit_icon`, with no
configuration anywhere for it, makes the browser generate one: the glyph from the host app's icon
registry, and the route from the resource base it already holds. Convention mode is the server
saying **which** actions a column offers, and stopping there.

```php
use TamasLabs\Aura\Table\{Action, Column};

Column::actions('id', Action::show(), Action::edit(), Action::destroy())
```

One header cell comes out, and nothing else:

```json
{ "content": null, "key": "id", "fields": ["show_icon", "edit_icon", "destroy_icon"] }
```

No `body.columnConfigs` entry, and no extra key in the rows.

| Action | Route the browser builds | Renders as |
| --- | --- | --- |
| `Action::create()` | `{base}/create` | link |
| `Action::show()` | `{base}/{key}` | link |
| `Action::edit()` | `{base}/{key}/edit` | link |
| `Action::destroy()` | `{base}/{key}/destroy` | a trigger for Aura's built-in confirmation modal |

`{base}` is `urlParameter`, an Aura **client** config prop. The server never sees it, which is both
why convention mode can be this thin and the limit of what it can do: a route of your own, a
different glyph, a modal id — those need a full configuration, and that is F5b.

`create` is the odd one. Its route carries no placeholder, yet Aura renders it in every row. That
is the client's behaviour, reproduced rather than corrected; a create button belongs in the toolbar.

Column methods still work on the cell itself — a heading, an alignment, a width:

```php
Column::actions('id', Action::edit(), Action::destroy())
    ->content('Actions')
    ->align('end')
    ->width('90px');
```

### The key is a placeholder, not a name

`Column::actions('id', …)` does not key the column `id` for tidiness. Aura writes that key into the
route it generates — `{base}/{id}/edit` — and fills it per row from the item field of the same
name. The key has to be the identifier the rows actually carry, which is normally the primary key.

So no other column may hold it, and the collision nearly every table meets first is the selection
column, whose key defaults to the model's primary key as well:

```php
Column::selection()->key('select'),          // ← re-key this one
Column::actions('id', Action::edit()),
```

Re-keying the selection column changes nothing about the selection: Aura reads the row id from that
column's `field`, never from its key (`resolve-row-id.ts`). A key only identifies a column inside
the payload.

The same holds for a visible `id` column — `Column::make('id')->key('identifier')` — which keeps
sorting and searching by `id`, because those travel by field.

---

### Escalation

Convention mode stops at the browser's own defaults. The moment anything is customised — a route, a
glyph, a colour, a label, a tooltip, a modal id — the field can no longer be left to the browser,
because a generated configuration would not carry the customisation. So the action **escalates**:
it emits the whole `body.columnConfigs` entry itself, and the preprocessor skips a field that
already has one.

The call surface does not change; only the payload does.

```php
Column::actions('id',
    Action::show(),                                    // convention: no config
    Action::edit()->title('Edit this user'),           // escalated
    Action::destroy()->asButton()->variant('danger'),  // escalated
)
```

Escalation is per action, not per column: the `show` above still costs nothing.

`asIcon()` / `asLink()` / `asButton()` choose the shape — the `_icon`, `_link` or `_button` suffix —
and are **not** a customisation, because Aura generates all three. `set()` reaches any other key the
trigger's configuration accepts (`size`, `target`, `rounded`, a `data-*` attribute) and does
escalate, like everything else.

Everything an action takes:

| Method | Does | Escalates |
| --- | --- | --- |
| `Action::create()`, `show()`, `edit()`, `destroy()` | the four resource actions | — |
| `asIcon()`, `asLink()`, `asButton()` | the shape, and so the field suffix | no |
| `icon(string $icon)` | the glyph — an `icons` registry key, not a CSS class | yes |
| `variant(string $variant)` | the colour; on a button used directly as `btn-{variant}` | yes |
| `label(string $label)` | the visible text of a link or a button — Aura's generated one is the bare prefix (`edit`) | yes |
| `title(string $title)` | tooltip text | yes |
| `alt(string $alt)` | accessible label; worth setting on an icon, which says nothing to a screen reader on its own | yes |
| `route(string $route)`, `routeName(string $name, array $parameters = [])` | where it goes | yes |
| `modal(string $id)` | the modal a `destroy` opens — escalates on its own, since a generated configuration can only ever name Aura's built-in one | yes |
| `allowedWhen(callable $allowed)`, `allowedWhenAll(callable $resolver)` | [per-row permissions](#per-row-permissions) | yes |
| `set(string $key, mixed $value)` | any other key the trigger's configuration accepts | yes |

An escalated action needs a route the *server* can build, which is what `$resource` is for:

```php
final class UserTable extends AuraTable
{
    protected ?string $resource = 'admin/users';
    // …
}
```

Without it — and without a route on the action itself — the build fails and says so. In convention
mode `$resource` is never needed: the browser builds the route from its own `urlParameter`. When
the base is not a constant — one resource per tenant, say — override `resource(): ?string` instead
of setting the property.

Two things escalation cannot reproduce byte for byte, both because the registries live in the
browser:

| | Generated by Aura | Escalated by the server |
| --- | --- | --- |
| an icon's glyph | `class: ['fas','fa-pen','text-primary']`, resolved from `icons` / `variants` | `icon` and `variant`, which `normalizeIconConfigs` resolves through the same registries in the same pass |
| a button's colour | `variants[prefix]`, falling back to `variants.primary` then `primary` | `primary`, unless `->variant()` says otherwise |
| a `destroy` modal | carries a decorative `key` | omits it — `resolveRoute` reads the route and the row, never the key |

The first and third change the payload and nothing else. The second is the one place where
escalating changes what the user sees, and only when the host app registered a variant under the
action's own name.

<a id="action-routes"></a>
### Routes

Three ways to say where an action goes, in order of preference:

```php
Action::edit()                                        // the convention — the browser builds it
Action::edit()->routeName('admin.users.edit')         // the route you already registered
Action::edit()->route('admin/users/{id}/edit')        // a path, spelled out
```

`routeName()` reads the route's URI **as it was registered** — `admin/users/{user}/edit` — never
through the `route()` helper, whose absolute URL Aura would turn into
`/https://app/example/com/admin/users/5/edit`. Parameters you name are substituted; the one left
over becomes the placeholder Aura fills from the row, under the action column's key:

```php
Action::show()->routeName('companies.users.show', ['company' => $company->id]);
// companies/7/users/{id}
```

A value that is itself a `{placeholder}` is passed through untouched, so a second row field can fill
a second parameter. More than one parameter left open is refused — only one can come from the row.

**A dot in an action route is refused**, unlike elsewhere in the package. Aura turns every dot into
a slash, so a Laravel route *name* passed where a path belongs (`users.edit`) resolves to
`/users/edit`: a real URL, with the identifier missing, and no error anywhere. `routeName()` is the
supported way to use a route name.

---

## Per-row permissions

Some rows may be edited and some may not. `allowedWhen()` says which, on the action or on the cell
configuration, and the cell simply is not there for the rows it denies:

```php
use Illuminate\Support\Facades\Gate;

Column::actions('id',
    Action::show(),
    Action::edit()->allowedWhen(fn (User $user) => Gate::allows('update', $user)),
    Action::destroy()->allowedWhen(fn (User $user) => Gate::allows('delete', $user)),
)
```

The callback is handed the row's **model**, not the array it flattens to — a policy wants the
object. Anything truthy allows the row.

> **Hiding a cell is not authorisation.** The row is still in the payload, the identifier is still
> in it, and the route is still in `columnConfigs` for anyone who reads the response. This keeps the
> table from offering an action the server would then refuse; the refusal itself has to live on the
> route. Give `allowedWhen()` the policy the route is protected by, not a second rule that happens
> to agree today.

The same call works on any cell configuration:

```php
Column::make('email')->as(
    Link::make()->route('users/{id}')->allowedWhen(fn (User $user) => Gate::allows('view', $user)),
)
```

### How it is emitted

Aura renders nothing at all when no `if` branch matched and there is no `else`
(`resolve-conditional-config.ts`). That is the mechanism. The gate is a hidden per-row flag, and the
configuration is one condition over it:

```json
{
  "type": "icon",
  "key": "_allowed_edit_icon",
  "if": [{ "true": true, "icon": "edit", "route": "admin/users/{id}/edit", "key": "id" }]
}
```

and the rows carry the flag:

```json
{ "id": 1, "last_name": "Lovelace", "_allowed_edit_icon": true }
```

Four properties of that payload are deliberate, and each is a way this could otherwise fail quietly:

- **The flag is in every row, `false` included.** A missing field reads as `undefined`, and an
  undefined flag hides the cell exactly as a denial does — so a gate that stopped running would look
  like a table where nobody is allowed anything. It is always there to be looked at.
- **It is a real `bool`.** Aura's `true` operator is an exact comparison (`fieldValue === true`), so
  a `tinyint` `1`, or the `"1"` a driver hands back, would deny every row without a word. Whatever
  the callback returns is cast.
- **The gate wraps the configuration rather than sitting beside it.** Everything the cell renders is
  inside the branch, including the caller's own `when()` / `otherwise()`. A configuration has one
  condition field, so a gate sharing the level with your own conditions would have to read the same
  field as they do, and an `otherwise()` beneath it would render the cell for precisely the rows the
  gate denied. Outside, it cannot be reached past — which is why the two are not refused as a pair.
- **`cellRules` stays at the root.** It is not content: Aura reads it from `columnConfigs[column.key]`
  and styles the `<td>` whether or not anything renders inside it.

The flag is named after the field it guards, with dots flattened —
`Column::make('company.name')` gates on `_allowed_company_name`, because a dotted name would send
Aura's `resolveValue` looking for a `name` inside an `_allowed_company` no row carries. Two gates
that would write one flag are refused rather than silently merged.

An action that is gated **escalates**, like any other customisation: a generated configuration
carries no condition, so the server has to emit the whole entry — which means `$resource`, or a
route on the action. An ungated action beside it still costs nothing.

### One query for the page, not one per row

`allowedWhen()` is handed a model that is already in memory and costs nothing. When the decision
needs a lookup the rows cannot answer on their own, `allowedWhenAll()` prepares it once for the
whole page and returns the per-row test:

```php
use Illuminate\Database\Eloquent\Collection;

Action::destroy()->allowedWhenAll(function (Collection $rows) {
    $locked = Lock::whereIn('post_id', $rows->modelKeys())->pluck('post_id')->flip();

    return fn (Post $post) => ! $locked->has($post->id);
});
```

The collection is the page as Eloquent models, so `modelKeys()`, `loadMissing()` and the rest are
there. The outer callback runs once per response; only the inner one runs per row. Returning
anything but a callable from it is refused.

### Caching

A gate is a closure, and the [cached definition](#caching-the-definition) is plain arrays. What the
cache holds is the flag's *name*, written into the definition as a condition; the callback that
fills it is collected fresh on every request, so `$cache = true` and `allowedWhen()` work together.

They can only drift in one direction. A cached definition that still names a flag no column fills
any more produces rows without that field — and an absent flag is not `true`, so the cell stays
hidden. A gate whose flag is no longer in any condition adds an unread field. Neither reveals
anything.

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
includes the rule that a cell naming no field must span at least two columns. `Footer::make()`
declares the first row; `row(Column ...$cells)` adds another below it, as many as the footer needs.

```php
public function settings(): TableSettings
{
    return TableSettings::make()
        ->stickyHeader()->headerHeight('48px')
        ->striped()->hoverable()
        ->stickyFooter();
}
```

The whole set: `stickyHeader()`, `headerHeight(string $height)`, `striped()`, `hoverable()`,
`stickyFooter()` and `footerHeight(string $height)` — the three flags take `bool $x = true`, the
two heights a CSS length such as `48px`.

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
| A cell naming both `field` and `fields` | the contract allows one or the other (`not: {required: [field, fields]}`); a cell carrying both fails Aura's own validation the same way |
| A cell configuration on a column whose key and field differ | the renderer is read under the field and the cell rules under the key, so only half of it would be used |
| A single configuration on a `combined()` column | Aura renders one segment per member field and looks each up by name — use `configure()` |
| `configure()` naming a field the column does not read | there is no segment for it |
| Conditional cell rules on a `combined()` column with no `->on()` | the conditions would be keyed by the column key, which is not a value in the row: every condition false, nothing ever styled, silently |
| A configuration that renders nothing (`Text` with no value, `Icon` with no glyph, `Modal` with no trigger) | the cell would come out empty |
| Conditions nested more than five deep | Aura resolves five and silently renders the truncated configuration |
| Conditions with no field to read | without a `key` Aura skips them and applies the base configuration — fail-open |
| An absolute route, or a placeholder outside `[\w.]+` | Aura turns every dot into a slash, so `route()`'s URL becomes a path; an unmatched placeholder stays in the URL verbatim |
| Two columns rendering the same field | `columnConfigs` is one flat map keyed by field, so the second entry replaces the first and the losing column renders the winner's configuration |
| `merge()` or `set()` naming `type`, `key`, `if` or `else` | those decide how everything else is read; a hand-written `key` beats the emitted one and takes the conditions with it |
| An action column whose key another column already holds | the key is the route placeholder Aura fills per row, so it is not free to change — the other column is |
| The same action in two columns, or twice in one | Aura generates one configuration per field name, so the second occurrence silently inherits the first one's route, built with the first one's key |
| An action field name (`edit_icon`, `destroy_link`, …) outside `Column::actions()` | Aura would build a route onto that cell against whatever key it happens to carry, and the column's own value would never render |
| An action column marked `sortable`, `searchable`, `filterable` or `globalSearch` | those flags reach the whitelist, and there is no database column behind an icon |
| `Column::actions()` with no actions | `fields` is typed `minItems: 1`, and Aura only treats a cell as a column when it names something |
| A `combined()` column with an empty `fields` | same rule |
| A customised action with no `$resource` and no route of its own | an escalated action emits its own route, and the server has nowhere to build it from |
| A `$resource` or an action route that is absolute, or contains a dot | Aura prefixes `siteName` itself and turns every dot into a slash — a route name passed as a path resolves to a real URL with the identifier missing |
| `routeName()` naming an unregistered route | the action would point at an empty path |
| `routeName()` leaving more than one parameter open | only one can be filled from the row: the one the action column keys on |
| Two permission gates writing one flag | the flag is named after the field it guards, so two fields differing only in a dot collide and one gate would decide both cells |
| `allowedWhen()` on a column that names no field | a configuration reaches the browser under a field name, and the flag is named after it |
| `allowedWhenAll()` returning anything but a callable | it is given the page and has to hand back the per-row test |
| A gate over conditions already five deep | the gate is a sixth level, and Aura resolves five |

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

`allowsSort(string $field)`, `allowsSearch(string $field)` and `allowsFilter(string $field)` answer
the three questions on their own, if you need the same decision somewhere else — in a policy, or in
a controller assembling something the table does not serve.

### AuraRequest

```php
AuraRequest::fromHttp(Request $request, FieldPermissions $fields, ?RequestLimits $limits = null): self
AuraRequest::fromArray(array $payload, FieldPermissions $fields, ?RequestLimits $limits = null): self
```

`fromHttp` reads the payload from where the contract puts it: the JSON body on `POST` / `PUT` /
`PATCH`, the query parameters on `GET` / `DELETE`. Both throw `ValidationException` — a **422** —
on anything the contract does not allow.

| Key | Type | Required | Notes |
| --- | --- | --- | --- |
| `page` | integer ≥ 1 | **yes** | |
| `paginate` | integer ≥ 1 | **yes** | clamped to `pagination.max` |
| `sortable[]` | `{field, direction}` | no | `direction` is `asc` or `desc`; one entry per field |
| `searchable[]` | `{field, term?, exact?, min?, max?}` | no | term search or range search; one entry per field |
| `filterable[]` | `{field, values[]}` | no | `values` may be empty, but must be present; one entry per field |
| `globalSearch` | string | no | at most `limits.term` characters |
| `selected[]` | string / number | no | row ids for bulk actions; at most `limits.selected` |

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

### What bounds a request

Every list and every string in a request is bounded before any of it reaches the query. Two
different mechanisms, and the split is the interesting part.

**The three field lists are bounded by the whitelist itself — there is no config key for them.**
Aura keeps exactly one entry per field: `use-sorting.ts`, `use-searching.ts` and `use-filtering.ts`
all look the field up and update the existing entry instead of pushing a second one. So a table
offering three sortable columns can never have produced a fourth sort, and `FieldPermissions` is
already the exact ceiling. Deriving it beats configuring it twice over: it is tighter than any
number worth defaulting to, and it cannot go stale when the columns change.

Two rules follow, both **422**:

- a list longer than the whitelist it draws from — checked *before* anything walks its rows, so an
  oversized payload is refused while counting it is still the only work done on it;
- the same field twice in one list. Aura never sends one, and two sorts on one field would mean
  `ORDER BY x ASC, x DESC`.

**Everything else comes from `limits`** — the search terms, the selection, the values of one
filter. See [Configuration](#limits--the-rest-of-the-payload) for the keys.

`selected` is the one list the server cannot derive a bound for: the selection survives paging
(Aura persists it and merges by union), so it grows with what the user ticks rather than with the
table. That is why it needs a number.

Pass a `RequestLimits` to override any of them for one call — anything left `null` comes from
config, so a partial override does not discard the rest:

```php
use TamasLabs\Aura\Request\RequestLimits;

AuraRequest::fromHttp($request, $fields, new RequestLimits(paginate: 50));
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

**A dotted field names a method, and the method is inspected before it is called.** `company.name`
means "call `company()` on the model" — so `delete.x` would mean calling `delete()`, and finding
out only afterwards that what came back was not a relation. Both the query layer and inference go
through `Support\Relations`, which checks first: the method has to be public, callable with no
arguments, **not declared by the framework**, and — if it declares a return type at all — that type
has to be a `Relation`.

The middle rule is the one doing the work: `Model::delete()`, `save()`, `push()` and about a
hundred others are untyped, so nothing but the declaring class separates them from an equally
untyped relation method on your own model. (Laravel's own `Model::isRelation()` is no help — it is
`method_exists() || relationResolver()`.) A relation carrying only a `@return` docblock keeps
working, which is why the guard stops where it does; what stays reachable is an untyped,
side-effecting method on your own model, named by one of your own columns.

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
| `UnsupportedRelation` | sorting through a to-many relation, a nested relation path, or a dotted field whose first segment is not a relation at all |
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

## Versioning

Three version numbers meet here, and they move independently:

| Number | What it counts | Today |
| --- | --- | --- |
| `tamas-labs/laravel-aura` | this package — semver, taken from the git tag | unreleased (`dev-main`) |
| `AuraContract::VERSION` | the wire contract it writes | **1.0** |
| `@tamas-labs/aura` | the Vue table that reads that contract | **1.0** |

**Compatibility is decided by the middle row, not by the other two.** Any Aura release that reads
contract 1.0 works with any release of this package that writes it, whatever their own version
numbers say — that is the point of publishing the schema separately, in
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema), instead of each side
carrying a copy. So the question this section has to answer is not "which Aura version?" but
"which contract version?", and the answer is the constant.

### What semver covers

**The public surface is two surfaces.** One is the PHP you call; the other is the JSON that
reaches the browser. A change that leaves every call in your table class identical and alters what
the browser draws is a breaking change all the same — the payload *is* the output of this package,
and the definition is often cached, so a host application cannot even see it happen.

| Change | Kind |
| --- | --- |
| A new builder method, a new `Condition`, a new preset | minor |
| A new inference rule filling a gap that was empty before | minor — with `->withoutInference()` as the opt-out |
| A key added to an emitted configuration that the renderer ignores | minor |
| The same definition emitting a differently shaped `columnConfigs`, header cell or whitelist | **major** |
| Renaming, removing, or narrowing the parameters of a documented method | **major** |
| A method marked `@internal` changing shape or disappearing | not a version event at all |
| Adding a PHP or a Laravel version to the matrix | minor |
| Dropping one | **major** |

The `@internal` line is not a matter of intent: `tests/DocsCoverageTest.php` reflects over `src/`
and fails when a method is neither documented in both full references nor marked `@internal`, so
the two lists are the same list. Everything documented here is covered; nothing else is. And the
covered set is written down — `tests/Docs/public-surface.txt` holds all 246 of them, and a build
that adds or loses one says so by name, in the direction that decides the release. See
[Development](#development).

### What the contract's own version covers

The response schema sets `additionalProperties: true`, so **the browser tolerates keys it does not
know** — a payload may grow within contract 1.0 without breaking a reader. The request schema sets
`additionalProperties: false`, so the opposite holds on the way in: anything this package invents
on the request side is a contract change, not an extension.

A move to contract 2.0 would be a **major** version of this package, because the payload it writes
would no longer be one an Aura reading 1.0 can render.

Nothing carries the version over the wire today. A host application that wants to assert the pair
has `AuraContract::VERSION` to compare against; there is deliberately no negotiation, no header,
and no field in the payload.

### Before the tag

Two things are true of `dev-main` that will not be true of a release, and they are worth stating
rather than discovering:

- **`tamas-labs/aura-schema` is pulled at `dev-main`, and `composer.lock` is not committed** (the
  library convention). Nothing fixes the upstream revision, so a schema change can turn CI here red
  with no commit in this repository, and an old CI run cannot be replayed. A git tag on
  `aura-schema` — a VCS repository resolves tags into semver versions, no registry needed — turns
  that constraint into `^1.0`. It is a release blocker, not a footnote.
- **The package is not on Packagist**, so `composer require tamas-labs/laravel-aura:dev-main` over a
  `repositories` entry is the only way to install it, and `dev-main` means "whatever `main` says
  today" — including a breaking change made an hour ago.

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
docker compose run --rm php composer test:coverage   # the suite, with the coverage floor
docker compose run --rm php vendor/bin/pest --filter "clamps paginate"
docker compose run --rm php vendor/bin/phpstan analyse
docker compose run --rm php vendor/bin/pint      # apply formatting
```

One service, `php`, on `php:8.4-cli-alpine`. **No database container** — the suite runs on
in-memory SQLite, so `docker compose up` is never needed.

The quality gate is Laravel Pint (`laravel` preset), PHPStan/Larastan at level **max** over `src/`,
`tests/` and `workbench/`, and Pest. CI runs the matrix natively (PHP 8.3/8.4 × Laravel 12/13) and
separately builds this image so the Dockerfile cannot rot.

**Coverage has a floor, and the floor is the gate.** `composer test:coverage` runs the suite with
`--min=90`, which fails the run below that number rather than printing a report nobody reads. The
threshold is not in `phpunit.xml` because PHPUnit has no fail-under of its own — Pest's `--min` is
the mechanism. The image carries **pcov** rather than Xdebug (the only thing wanted here is the
line count, and pcov measures it far more cheaply), restricted by `pcov.directory` to the same
`src/` that `phpunit.xml` declares, so neither `vendor/` nor the tests are instrumented. CI measures
once, on the newest supported pair — the number does not differ per matrix leg.

The nine points between the floor and 100 are almost entirely the fluent setters: one-line
`return $this->set('align', $align)` methods on the cell types and their traits, documented but
never called by a test. They are the surface the contract's 73 property names live on, and a guard
that every setter emits the slot it claims is worth more than the coverage number it would move.

**The public surface is recorded, not remembered.** `tests/Docs/public-surface.txt` lists every
method the version promise covers — the same set the documentation guard walks — and
`tests/PublicSurfaceTest.php` rebuilds it and fails on any difference. It refuses nothing; it only
makes the direction explicit, because a method that appeared is a minor release and one that
vanished is a major one, and an `@internal` added to a documented method is the second of those.
Both read as ordinary commits without the record.

**The documentation is part of the gate.** `tests/DocsCoverageTest.php` reflects over every class
under `src/` and fails when a public method is mentioned in neither full reference — or in only one
of the two, which is how the English and the Hungarian text drift apart. A method excluded from
that count is excluded by being marked `@internal`, on itself or on the class: the same tag that
says the version promise does not cover it. So adding a builder method means documenting it in
`README.en.md` **and** `README.hu.md` in the same change, or saying in the code that it is not
yours to call.

<a id="the-demo-application"></a>
### The demo application

`workbench/` is a small Laravel application — one model, one table class, one route — that exists
to be pointed at a browser. It is the one question the test suite cannot answer: whether Aura's own
preprocessor really turns `show_icon` into a link, and whether an escalated configuration renders
the same as the generated one.

```bash
docker compose run --rm php composer build      # create the SQLite file, migrate, seed
docker compose run --rm php composer serve      # build, then serve on http://localhost:8000
```

Then point the Aura dev server in `v1.0/` at `http://localhost:8000/api/employees`. CORS is open
for every origin — this application never leaves a laptop — so the Vite dev server on its own port
can call it straight away.

`workbench/app/Tables/EmployeeTable.php` is the demo table, and it is deliberately one of
everything: an enum-backed badge and filter, a currency column with a conditional colour, a
progress bar with thresholds, a relation sorted through a subquery, and an action column carrying
all three action modes at once — `show` in convention mode, `edit` escalated by a tooltip,
`destroy` gated by a per-row permission.

Nothing under `workbench/` ships: `.gitattributes` marks it `export-ignore`, and it is not in the
package's autoload.

> The demo writes a `.env` into Testbench's skeleton inside `vendor/`, and a skeleton `.env` is
> read by every later test run. `phpunit.xml` therefore pins the cache, queue and session drivers
> the suite depends on, so running the demo cannot change what the tests do.

---

## Roadmap

| Phase | Subject | State |
| --- | --- | --- |
| **F0** | Docker and repository scaffold | ✅ done |
| **F1** | Contract test harness | ✅ done |
| **F2** | Query side — request → Eloquent → `items`/`meta`/`links` | ✅ done |
| **F3** | Definition core: `AuraTable`, `Column`, inference, caching | ✅ done |
| **F4** | Cell builders: badge, link, button, icon, modal, progress, conditional configuration | ✅ done |
| **F5a** | Actions in convention mode: `Action`, `Column::actions()` | ✅ done |
| **F5b** | Escalation to an explicit `columnConfig`, and route building | ✅ done |
| **F5c** | Per-row permissions — the response side | ✅ done |
| **F6.1** | Demo workbench app | ✅ done |
| **F6.2** | `make:aura-table` | ✅ done |
| **F6.3** | Documentation-coverage guard, and the `@internal` line under the version promise | ✅ done |
| **F6.4** | Versioning and the binding to the contract version | ✅ done |
| **F6.5** | The Packagist release | planned |

---

## License

MIT — see [LICENSE](./LICENSE).

## Author

Tamas Balint
