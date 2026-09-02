# Changelog

The format follows the [Keep a Changelog](https://keepachangelog.com/hu/1.1.0/) recommendation.
The package targets **contract 1.0** (`TamasLabs\Aura\AuraContract::VERSION`); the contract
version is independent of the package version.

## [Unreleased]

### Added

- **Error log ingestion (F7): the server side of Aura's built-in `errorReporting`.** Aura POSTs
  an ECS-format error log to an arbitrary endpoint; until now, the host application had to write
  that endpoint, and a host that did not write it caused its table to silently swallow its own
  validation warnings — exactly the class of error **this package causes**. It can be enabled with
  one `.env` line:

  ```dotenv
  AURA_ERRORS_ENABLED=true
  ```

  New surface: `TamasLabs\Aura\Errors\{ErrorStore, LogErrorStore, DatabaseErrorStore,
  AuraErrorRecord}`, the `routes/aura-errors.php` route file, the publishable
  `create_aura_errors_table.php.stub` migration, and the `aura:errors` Artisan command.
  `AuraContract::VERSION` **remains 1.0** — this is not a request/response contract change.

  Four rules that follow from the client's **measured** behavior, not taste:

  - **The success response is `202`, even when entries are dropped.** The client treats every
    non-2xx response as a failure, resends four times, then repeats the batch with exponential
    backoff, putting it at the **front** of the queue. A 422 for one malformed entry is therefore
    not a message to the developer but an unrepeatable request repeated forever. 4xx is reserved
    for what resending cannot fix: `413` over the size limit and `429` from the throttle.
  - **The route must not be in the `web` group.** The client uses native `fetch()` and sends no
    CSRF token, so `web` would answer with 419 — forever. The packaged `middleware` default is
    `['throttle:60,1']`.
  - **The request size and `metadata` are limited.** A `HeaderValidator` entry carries the entire
    rejected `header` section in `metadata.receivedValue`, and the client limits nothing. The
    limits are 8 KB per entry (`metadata.max_bytes`), 1 MB per request (`max_payload`), and
    `metadata` storage can be disabled — it is the only field that may carry **typed** search
    terms from the user (`SessionStateValidator`).
  - **Storage is idempotent.** `fingerprint` = `sha256(key|component|action|type|message|timestamp)`,
    with a unique index: submitting the same batch four times leaves one row. Without it, the
    error table would measure its own outages.

  Two counters, and **not the same number**: `occurrences` belongs to the client (how many times
  the error occurred in one browser, as Aura's store merged it), while `receipts` belongs to the
  server (how many times the entry arrived, including resends).

  Resolved open questions: the package has **no retention** (that belongs to the host; the README
  explains how to schedule it), and the `database` driver's migration is **publishable**, not
  automatically loaded — a library that produces JSON and one that creates a table in your
  database make two different promises.

  One planned deviation: `ErrorReportRequest` became the `ErrorBatch` value object rather than a
  `FormRequest`. `FormRequest` lives in `Illuminate\Foundation`, which is not among the package's
  granular `illuminate/*` dependencies — and its failure mode is the 422 excluded by the first
  rule.

- **New contract document in `tamas-labs/aura-schema`:** `aura-error-report.schema.json`, with its
  example and bundle. The entry has `additionalProperties: true`, so a future Aura field will not
  break everything. New test helper: `assertMatchesAuraErrorReportSchema()`.

### Changed

- **Two new `illuminate/*` dependencies** (`log`, `routing`) for the error-log endpoint — both
  with the same `^12.0 || ^13.0` constraint guarded by `tests/VersioningTest.php`.
- **The contract dependency is pinned (audit P8 — the last red release blocker).**
  `tamas-labs/aura-schema` received the `v1.0.0` tag, and the constraint changed from `dev-main` to
  **`^1.0`**. A VCS repository resolves git tags as semver versions without a registry, so the
  absence from Packagist is no obstacle. Until now — because `composer.lock` is not committed for
  a library package — **nothing pinned the upstream revision**: a schema change could turn this
  repository's CI red without a single commit here, and an old run could not be replayed. A new
  guard in `tests/VersioningTest.php` rejects a constraint beginning with `dev-` or `*` on the
  contract package because that would restore exactly this state.
- **Seven incorrect contract descriptions were fixed upstream before the tag.** The audit named
  five (`true` / `false` / `empty` / `eq` exactness and the alleged default for `key`) plus INV10;
  reading Aura's source found two more: `null` / `notNull` do not treat `undefined` as the schema
  claimed (a field missing from the row **matches** `notNull`), while `gt` / `gte` / `lt` / `lte` /
  `between` first try a date and then expect a **number** on both sides — a numeric string silently
  evaluates false (INV11). **Validation did not change**: the documents accept and reject exactly
  what they did before. This package's behavior did not change either — it already emitted according
  to reality; what disappeared was the fact that two public repositories published two contradictory
  truths.

### Fixed

- **All four CI matrix legs were red — discovered as a side effect of P7.** Three independent
  causes, none visible in the repository:
    - **On all four legs**: the matrix pinned the Laravel version with `composer require --no-update`,
      which **writes the constraint into `composer.json`** — but `VersioningTest` reads
      `composer.json` as the truth for what the two README requirement lists must quote verbatim.
      The pin therefore broke exactly the two tests it was meant to protect, on every leg since
      F6.4. The fix is `composer update --with`, which applies the constraint only for that run and
      does not touch the manifest; a new test rejects any `composer require` in the `test` job.
    - **On the Laravel 12 legs**: the `argument.type` half of the `@phpstan-ignore` in
      `src/Query/AuraQuery.php` **matches only Laravel 13** (13 declares `Expression` as a generic
      and types its constructor with the template, 12 does not); an unmatched inline ignore is
      itself an error — and cannot be suppressed as `ignore.unmatchedIdentifier`. That half moved
      to `phpstan.neon` with `reportUnmatched: false`; `return.type` stayed beside the code.
    - **On PHP 8.5**: `tests/ActionColumnTest.php` — the `int|string|null` from `array_key_last()`
      could be an invalid array key. Empty arrays now return early.

### Added

- **Release workflow and community files (audit P5).** `.github/workflows/release.yml` starts on a
  `v*.*.*` tag. **It publishes nothing, and that is the point**: Composer installs from the git
  tag, so the tag *is* the release — by the time the workflow starts, it is already public and
  there is nothing to abort. It reruns the gate with its coverage variant, **rejects a tag without
  its own dated `CHANGELOG.md` section** (the manifest deliberately has no `version` key, so the
  changelog is the only record a tag can be measured against), and publishes that section as
  release notes. `bc-check` does **not** run again here: it compares against the last tagged minor
  version, which at tagging time is this tag itself — it would compare the release with itself.
    - A test pins that the gate and changelog check run **before the step that creates the release**.
      After it would be decoration; this is checked by mutation.
    - `SECURITY.md` — a private advisory, and **names the boundary** rather than gesturing at one:
      `FieldPermissions` and the single raw SQL expression on one side, and on the other the two
      things that intentionally are *not* a boundary (`allowedWhen()` hides but does not authorize;
      the payload is public data).
    - `CONTRIBUTING.md` — the Docker-only command table, the gate, the rules enforced by tests
      (documentation twice or `@internal`, the surface record, the schema does not live in this
      repository), and the release process. Both are `export-ignore`.
    - `CODEOWNERS`, `dependabot.yml` (`github-actions` + `composer`), issue templates, and PR
      template. Dependabot has two omissions that are **claims**: it leaves `illuminate/*` majors
      alone because the supported Laravel majors are a release decision that must update both
      README requirement lists in the same commit; there is no `docker` entry because the base
      image's PHP version is the local half of the CI matrix — a bot proposing 8.5 while the matrix
      ends at 8.4 would propose an untested claim.
    - The issue templates ask about the **boundary between the two packages**: the bug report asks
      for the emitted payload (which separates which side is at fault), while `config.yml` routes
      rendering issues to the Aura repository and contract issues to aura-schema.

- **PHP 8.5 in the CI matrix (audit P7).** The matrix has six legs: 8.3 / 8.4 / 8.5 × Laravel 12 /
  13. All six were also run locally, in a separate image per PHP version, with the CI steps (Pint,
  PHPStan `max`, Pest): **391 tests / 1527 assertions, green on every leg**. Coverage remains on
  the 8.4 leg deliberately: coverage is the part of a toolchain that arrives late after a new PHP
  release, and its absence would fail a job unrelated to PHP 8.5.

- **BC-break checking in CI (audit P3).** New `bc` job and `composer bc-check` script:
  [`roave/backward-compatibility-check`](https://github.com/Roave/BackwardCompatibilityCheck) compares
  the public API in `src/` with the last tagged minor version (it reads the `autoload` paths, so it
  does not see the tests or workbench). It catches what the surface record cannot — an added
  parameter, narrowed type, widened return type, or class made `final` — and its boundary is the
  same `@internal` that F6.4 draws: it skips marked symbols but reports `@internal` **added to** a
  documented method as a break. The first trial run showed this immediately: it said exactly that
  about `ColumnGroup::columns()`, marked in P2.
    - **It is not a dev dependency, and cannot be**: it requires PHP `~8.4.0 || ~8.5.0` alongside
      this package's `^8.3` floor and the 8.3 CI leg, while its `composer/composer` and
      `symfony/console` constraints are very likely to conflict with Laravel's. The script installs
      it on its own into `build/bc-check`; a test forbids it in either `require` or `require-dev`.
    - **Before the first tag there is nothing to compare**, so the job sits behind a guard step that
      prints this and starts automatically with the first tag. The guard and a shallow clone would
      be a fail-open pair: without `fetch-depth: 0`, `git tag -l` is empty even in a released
      repository, and the job would report green because it found no break. That is why the history
      is complete and why `VersioningTest` covers it.
    - The tool uses `git clone`, so it compares **committed** revisions: the working copy is invisible
      to it.

- **The public surface is recorded (audit P2 closed).** `tests/Docs/public-surface.txt` contains the
  246 methods covered by the version promise; `tests/PublicSurfaceTest.php` rebuilds it and fails
  on any difference. **It forbids nothing — it states the direction**: a method that appears is a
  minor release, one that disappears is a major release, and `@internal` added to a documented
  method is the latter. Without the record both look like ordinary commits: the docs guard knows
  only that something is documented, the version guard only that the promise is stated — neither
  notices that the promise itself changed.
    - Of the roughly 30 plumbing methods named by P2, 6.3 had already marked 28. Of the two left,
      `ColumnGroup::columns()` is now `@internal` (only the definition builder reads it; a group is
      built by **passing** its columns, not reading them back), while `AuraQuery::apply()` remains
      **intentionally public**: it is the half below `paginate()` that lets the host application get
      the filtered builder without pagination (`export`, `count`, `chunk`), and both references
      document it that way.
    - `ColumnGroup::columns()` is also the first proof that the docs guard's leniency **lets things
      through**: the method counted as documented only because every README describes
      `AuraTable::columns()`, and the pattern matcher searches for the name regardless of class.
      The guard is not becoming stricter — the cell types intentionally receive shared documentation
      blocks — but the surface record now records by class what belongs to the promise.
    - The short class name can be used as an identifier because the test proves it: two classes with
      the same short name under `src/` fail the run before the record can refer to the wrong class.

- **`LICENSE` file and Packagist metadata (audit P1 + P9).** `composer.json` already declared
  `"license": "MIT"`, but the text was missing from the repository — an installed copy would thus
  have arrived without a license. Alongside it, `homepage` and `support.issues` / `support.source`,
  which Packagist displays on the package page. Two new guards in `tests/VersioningTest.php` tie the
  metadata to reality: one proves that the file exists, is MIT, and is **not** `export-ignore`d by
  `.gitattributes` (the kind of error visible only in the released package); the other proves that
  all three URLs point to the package's own repository. All three READMEs now refer to the file in
  their license section instead of merely saying “MIT”.

- **Coverage measurement with a threshold (audit P4).** The image now includes **pcov** (not
  Xdebug: line counting is the only goal here), narrowed with `pcov.directory` to the same `src/`
  declared by `phpunit.xml` — `vendor/` and tests are not instrumented. New scripts:
  `composer test:coverage` (`pest --coverage --min=90`) and `composer quality:coverage`. The
  threshold was **not** put in `phpunit.xml`, as the audit proposed, because PHPUnit has no own
  fail-under mechanism — Pest's `--min` switch is what fails below the number. CI measures once,
  on the newest supported pair (PHP 8.4 / Laravel 13); the number does not vary by matrix leg, so
  measuring four times would only cost time. **Today's value is 91.1 %**, and the missing part is
  almost entirely the fluent setters: one-line `return $this->set(…)` methods documented but never
  called by a test.

- **Explicit version promise (F6.4).** New *Versioning* section in both full references, plus the
  `tests/VersioningTest.php` guard that ties the text to the code.
    - **Three version numbers meet here, and compatibility is decided by the middle one**: the
      package (semver, from the git tag), `AuraContract::VERSION` (**1.0**), and the `@tamas-labs/aura`
      Vue table's version (**1.0**). Any Aura that reads contract 1.0 works with any release that
      declares it — hence the schema lives in a separate package.
    - **The public surface has two surfaces**: callable PHP **and** JSON reaching the browser. A
      change that leaves every call unchanged but produces different `columnConfigs`, header cells,
      or a whitelist for the same definition is **major** — the payload is this package's output,
      and the definition is cached as well. A table states what is minor and what is major; an
      `@internal` method is not a version event at all.
    - **The contract's own asymmetry is also stated**: the response schema has
      `additionalProperties: true` (the payload may grow within 1.0), while the request schema has
      `additionalProperties: false` (anything invented on the request side is a contract change).
      Moving to contract 2.0 would be a major package version.
    - **Two consequences of `dev-main` before the tag**: `aura-schema` was unpinned (a release
      blocker, not a footnote), and installing from `dev-main` means “whatever `main` says today”.
    - **The guard records five things**: both READMEs state the same contract version as the
      constant; both quote the PHP and Laravel constraints from `composer.json` verbatim; every
      Illuminate component carries the same constraint (otherwise “Laravel `^12.0 || ^13.0`” in
      the singular would be a lie); the manifest has no `version` key (the tag is the version); and
      both references name the Vue package at the other end of the contract.
    - The requirement lists now state the **actual constraint** (`^8.3`, `^12.0 || ^13.0`), not the
      tested versions — so it is also clear that `^8.3` permits 8.5, which the matrix has not yet run.

- **Documentation-coverage guard (F6.3).** `tests/DocsCoverageTest.php` walks every class in `src/`
  by reflection and fails when a public method appears in neither full reference — or in only one,
  because that is how the two languages drift apart. The pre-release audit found **130 of 284
  public methods** missing from both READMEs; that number is now **zero**, and manual discipline no
  longer maintains it.
    - **The omission marker is `@internal`**, on the method or class — the same marker saying that
      the `v1.0.0` version promise does not cover it either. The package received **83 new markers**:
      the 38 named constructors of exceptions, the `resolve()` methods crossing the
      `Column` / `Action` / `CellConfig` namespace boundary, `TableBlueprint` cache serialization,
      and the `RowPermissions`, `NumericFields`, `EnumPresentation`, and `Inference` classes as a
      whole.
    - **The documented surface is therefore 249 methods**, and they appear in both references. The
      guard still omits three things, each for a reason: a trait method is counted on the trait, not
      every class using it; a method overriding an `@internal` ancestor is excused after its
      ancestor (hence marking `CellConfig::type()` is enough instead of marking all nine types);
      and framework hooks (`register()`, `boot()`, `handle()`) are not demanded because the reader
      does not call them.
    - **New section in both references** (*Every builder method*): the cell layer's full surface,
      the four shared blocks (formatting chain, typography, mapping, route), and what each type adds.
      Also included are the `CellRules` frame and padding methods, `Footer::row()`,
      `TableSettings::footerHeight()`, the complete action call table (`alt()`, `modal()`, and which
      calls escalate), the three `allows*()` questions on `FieldPermissions`, and overriding
      `resource()` instead of the property.

- **`make:aura-table` (F6.2).** `php artisan make:aura-table UserTable --model=User` sketches the
  class from the model's **own database table**, rather than guessing from property names: one
  `Column::make()` per column, with the flag justified by its type and cast.
    - `BackedEnum` cast or boolean -> `->filterable()`; every other readable type ->
      `->sortable()->searchable()`. `currency`, alignment, and the range input come from the cast
      at build time, so the generator does not repeat them.
    - **The primary key is omitted, and the foreign key gets a comment** (`Column::make('company.name')`);
      `json` / `blob` and the model's `$hidden` are omitted as well. The generator leaves a comment
      wherever it refused to decide.
    - **The selection column is generated with `key('select')`.** Both selection and action column
      keys would default to the model key, and the action column's key cannot move — it is the
      route placeholder itself. Re-keying costs nothing: Aura reads the row identifier from `field`.
    - **It invents no editorial decisions**: no `globalSearch()`, no `->as(…)`, no `$resource` —
      the generated docblock states what should be added by hand. If the database is unreachable,
      it writes a placeholder and warns.
    - `--model` is optional (inferred from the class name, like `make:policy`), a model outside the
      application namespace is used as given, and `stubs/aura-table.stub` overrides the template.
    - **An invalid `--model` has a non-zero exit code.** Laravel casts the command return value with
      `(int)`, so a generator's `false` exits with **0** — a script could not distinguish a mistyped
      model from a written file.

### Changed

- **`composer.json` now names every Illuminate component used by `src/`**: `illuminate/database`,
  `illuminate/http`, and `illuminate/validation` were missing from `require` even though the
  package uses Eloquent, `Request`, and `ValidationException`; `illuminate/console` is now included
  for the command. In a Laravel application this changes nothing (`laravel/framework` brings all
  of them), but component-based installation previously had an incomplete dependency list.

- **Demo application (F6.1).** `workbench/` is a Testbench workbench: one model, one table class,
  one route — run `composer serve`, and the Aura dev server in `v1.0/` can point to
  `http://localhost:8000/api/employees`. This is the one question the test suite cannot answer:
  whether Aura's preprocessor really renders what this package emits.
    - `EmployeeTable` intentionally has one of everything: enum-based badge and filter, currency
      column with conditional color, progress thresholds, a relation sorted by subquery, and an
      action column containing all three action modes at once (`show` by convention, `edit` escalated
      for its tooltip, `destroy` gated by per-row permission).
    - Nothing under `workbench/` enters the package (`.gitattributes` `export-ignore`), but it is in
      the gate: Pint, PHPStan `max`, and `tests/Workbench/DemoAppTest.php`, which exercises routes,
      CORS preflight, the whitelist, and the gated action through a live HTTP request.
    - **`HandleCors` is registered globally**, not as route middleware: the preflight `OPTIONS` goes
      to a path with no route, so route middleware would never run. The `api` middleware group must
      also be registered by hand — Testbench boots with an empty middleware stack.

### Fixed

- **`phpunit.xml` now fixes `CACHE_STORE` / `QUEUE_CONNECTION` / `SESSION_DRIVER` values.** The
  demo build writes a `.env` into Testbench's skeleton under `vendor/`, which every later test run
  reads; its `CACHE_STORE=database` made the cache tests look for a `cache` table that nothing here
  creates. Running the demo therefore no longer changes what the tests do.

- **Per-row permissions (F5c).** `allowedWhen()` on an action or any cell config means the cell is
  absent for a row rejected by the callback. The callback receives the row's **model**.
    - **The gate wraps the configuration; it does not sit beside it.** The root is `type`, the
      hidden flag as `key`, and one `if` branch, **with no `else`** — Aura renders nothing exactly
      in that case (INV3, `resolve-conditional-config.ts:94`). Everything else, including the
      caller's own `when()` / `otherwise()` calls, goes inside the branch. A config has one
      condition field, so a gate at the same level would read the same field as your conditions,
      while an `otherwise()` beneath it would render the cell precisely for rejected rows. It
      cannot be bypassed from outside — hence we do not forbid the two together, as F5c.3 of the
      plan originally contemplated.
    - **The flag is present in every row, including `false`,** and is a **real `bool`** (INV4): Aura's
      `true` operator is exact (`fieldValue === true`), so a `tinyint` `1` or a `"1"` would deny every
      row. A missing field also hides, making a stopped gate indistinguishable from “nobody may do
      anything” — which is why it is always written.
    - **The route placeholder goes into the branch** (INV13/INV14): at the root, `key` is the
      condition selector removed by `stripLogicProps`, so a gated icon rendered with the root key
      would render without a link — silently. The existing branch-keying handles this, unchanged.
    - **`allowedWhenAll()`** prepares the decision once for the entire page (it receives an Eloquent
      collection and returns the per-row test), while the per-row form receives a model already in
      memory — neither queries per row; a `DB::listen` test pins this.
    - **The flag is named after the field, with dots flattened** (`company.name` ->
      `_allowed_company_name`): a dotted name would make Aura's `resolveValue` look inside a nested
      object and deny every row. Two gates writing one flag are rejected.
    - **A gated action escalates**, like any customization — generated configuration would not carry
      the condition. The `destroy` gate is placed on the modal, not the icon inside it.
    - **Cache drift can only fail closed.** The cache stores the flag's *name*, while the filling
      closure is rebuilt fresh for every request; if the definition names a flag nobody fills any
      more, the field is missing, and a missing field is not `true` — the cell remains hidden.
    - The documentation states: **hiding is not authorization**. The row, identifier, and route are
      in the payload; denial belongs on the route, and `allowedWhen()` receives the same policy.

- **Action escalation and route building (F5b).** Any customization — route, icon, color, label,
  tooltip, modal id — makes the action itself emit the complete `columnConfigs` entry, because the
  generated configuration would not carry the customization. The call surface is unchanged, only
  the payload; escalation happens **per action**, not per column.
    - `Action::asIcon()` / `asLink()` / `asButton()` choose the shape (`_icon` / `_link` / `_button`)
      and **do not** escalate: Aura generates all three. A table-driven test records all 12
      combinations according to the Aura preprocessor's output.
    - `Action::route()`, `routeName()`, `icon()`, `variant()`, `label()`, `title()`, `alt()`,
      `modal()`, and the `set()` escape hatch escalate.
    - **`AuraTable::$resource`** is the base of an escalated route. In convention mode it is never
      needed: the browser builds the path from its own `urlParameter`, which the server cannot see.
      Its absence throws clearly; `$resource` is validated even when nothing escalates because the
      definition is cacheable and the error would otherwise appear on a random request.
    - **`routeName()` reads the route URI as registered** (`admin/users/{user}/edit`), not through
      the `route()` helper — its absolute URL would become `/https://app/example/com/...` in Aura.
      It substitutes the named parameters; the one left open is the row-filled placeholder under
      the action column's key. More than one open parameter is a build-time error instead of 422.
    - **A dot is forbidden in an action route** (unlike the rest of the package). Aura replaces every
      dot with a slash, so a route name written in the route's place (`users.edit`) resolves to
      `/users/edit`: a real URL with the identifier missing, and no error anywhere.
    - What escalation cannot reproduce because the registries live in the browser: the icon's
      resolved `class` (instead, `icon` + `variant`, which `normalizeIconConfigs` resolves the same
      way) and the button's `variants[prefix]` color (instead, `primary`). The `destroy` modal's
      decorative `key` is deliberately omitted — `resolveRoute` never reads it.

- **Action columns in convention mode (F5a).** `Action::create()` / `show()` / `edit()` /
  `destroy()` and `Column::actions('id', …)`: the four Laravel-resource actions in one call.
    - **Only a header field is created; no `columnConfigs` entry.** The cell is
      `{"content": null, "key": "id", "fields": ["show_icon", …]}`, and nothing is added to
      the rows. The route base (`urlParameter`) is client-side config invisible to the server —
      hence convention mode stops here, and cannot do more. A custom route and icon are F5b.
    - **The key is the route placeholder, not a name.** Aura builds `{base}/{id}/edit` with the
      cell key and fills it per row from the item field of the same name, so the key must be the
      identifier. No other column may claim it; the error specifically calls out the selection
      column because its key also defaults to the primary key — and re-keying is safe because Aura
      reads the row identifier from `field` (`resolve-row-id.ts`).
    - **The action column goes in the last header row** (INV9), including grouped headers; the
      placeholder above it carries the same key, so the generated route is byte-for-byte identical.

### Fixed

- **A fixed `value` is no longer lost behind a defaulted `field`.** `Link::make()->value('X')`,
  `Button::make('X')`, and `Badge::make()->value('X')` all received the column field as `field` —
  and all three renderers read `field` **first**, only then falling back to `value`
  (`action-node-helpers.ts`, `renderBadgeNode`, `renderProgressNode`). The supplied label therefore
  never appeared, silently. `CellConfig::supersedesField()` now also lists `value`, and the check
  examines **settings**, not only explicit attributes. `Reference` is the exception and says so:
  its renderer reads `value` first, so the field is harmless there.
- **Four new build-time guards around action convention.** Each would have prevented silently wrong
  rendering:
    - **The same action twice** (in two columns or one) is `InvalidDefinition`. `columnConfigs` is
      keyed by field name, so the second occurrence would have inherited the first one's route,
      built with the first column's key.
    - **An action field name outside `Column::actions()`** (`Column::make('edit_icon')`,
      `Column::combined('x', ['name', 'destroy_link'])`) is `InvalidDefinition`. Aura would also
      have built a route for that cell, whatever its key, while the column value would never render.
      All 12 combinations are recognized (four prefixes × `_icon` / `_link` / `_button`); a generic
      prefix such as `status_icon` remains untouched because it is not an action.
    - **Sortable / searchable / filterable action column** is `InvalidDefinition`. Previously it
      received `multiFieldNeedsReference`, which suggested `->reference('…')` for an icon column.
    - **An empty `fields` list** is `InvalidDefinition` (`Column::actions('id')` with no action,
      `Column::combined('x', [])`). The schema says `minItems: 1`, and Aura considers a cell a column
      only when it names something.

### Security

- **Every list and every string in the request is limited.** Until now only `paginate` was limited;
  measured, 5,000 `sortable` entries built **125 kB of SQL**, `globalSearch` accepted 200,000
  characters, and `selected` accepted 50,000 identifiers. All three now return 422.
    - **The whitelist limits the three field lists, with no config key.** Aura keeps one entry per
      field (`use-sorting.ts:23`, `use-searching.ts:45`, `use-filtering.ts:41`), so `FieldPermissions`
      is already the exact ceiling: three sortable columns allow three sorts. Length validation runs
      **before** anything traverses the rows.
    - **The same field twice in one list returns 422.** Aura never sends this, and two sorts on one
      field would mean `ORDER BY x ASC, x DESC`.
    - **`RequestLimits`** value object and **`aura.limits`** config block for the rest:
      `limits.selected` (1000), `limits.values` (200), `limits.term` (255). `selected` is the only
      list whose ceiling the server cannot derive — selection survives pagination. A missing or
      non-positive config value falls back to the packaged default, not to “no limit”.

### Fixed

- **A dotted-field method is inspected before it is called** (`Support\Relations`, `@internal`).
  `method_exists()` was the only guard in two places (`Inference::resolve()`,
  `AuraQuery::toOneSubquery()`), so `Column::make('delete.x')` **called the model's `delete()`**
  while building the header, and only then discovered that the result was not a relation.
    - Laravel's `Model::isRelation()` is not suitable: it uses `method_exists() || relationResolver()`
      and says nothing about the return type. `delete()`, `save()`, `push()`, `refresh()`, and about
      a hundred other `Model` methods have **no type declaration**, so inspecting the return type
      would not catch them either — the **declaring class** is the only separator.
    - The rule is therefore: public, non-static, callable without arguments, not declared by
      `Illuminate\`, and, when it has a return type, that type is `Relation`. A relation with only
      an `@return` docblock **continues to work** — the stricter rule would break every older model.
    - New `UnsupportedRelation::notARelation()`: a non-relation previously received “only one
      relation level is supported”, an answer to a question nobody asked.
- **The fourth structural rule of the header schema is now checked.** `field` and `fields` exclude
  each other (`not: {required: [field, fields]}`), and `Column::assertValid()` previously checked
  only three of the four rules. A header cell that fails the response schema could be emitted through
  the `set('fields', …)` escape hatch — and Aura's own validation rejects the whole table, not one
  column. It is now `InvalidDefinition`.
- **A conditional cell rule on a multi-field column without `->on()` now throws at build time.**
  Its rule key became the column key (`full_name`), which is not a row value: Aura reads `undefined`,
  every condition is false, and the cell is never styled — without notice. The package already threw
  for the analogous `columnConfigs` case (`configNeedsMatchingKey`), but not this one. An unconditional
  rule set remains valid: it does not emit `key` either.

### Changed

- **`AuraTable` was split apart** (692 -> 222 lines), before the F5 action layer that would have
  written into the same place. Four new `@internal` classes in the `Table\` namespace, with no
  behavior change: `DefinitionBuilder` (definition, whitelist, and numeric fields in one pass),
  `CellConfigs` (the `columnConfigs` map), `ColumnPermissions` (the whitelist read from cells), and
  `ResolvedColumn` (the column/header-cell pair — previously a destructured tuple in eight places).
  `AuraTable` itself is now responsible only for the table: columns, model, settings, cache, and
  `respond()`.
    - The header's `searchableItems` is now read **from the whitelist itself**, not from a second
      identically built list — the “single source” property is structural.
    - Proven: the definition, whitelist, `numericFields`, and error messages are bit-for-bit
      identical to the pre-refactor output across several tables (grouped header, footer, all nine
      cell types, conditions, cell rules) and four error paths.

- **The third parameter of `AuraRequest::fromHttp()` / `fromArray()` changed from `?int $maxPaginate`
  to `?RequestLimits $limits`.** The `paginate` ceiling remains `aura.pagination.max`; it now travels
  with the other limits, and overriding one limit no longer discards the others.

### Added

- **Docker-based development environment (F0):** a single `php` service on `php:8.4-cli-alpine`,
  with `intl` + `zip` compiled and `mbstring` / `pdo_sqlite` checked at build time. There is no
  database container — the suite runs on in-memory SQLite.
- **`AuraServiceProvider`** and publishable `config/aura.php` (`aura-config` tag). For now it has
  one key: `pagination.max` is the upper bound for client-provided `paginate`, not a suggestion.
  There is deliberately no default page size — `paginate` is required by the contract.
- **`AuraContract::VERSION`** — the targeted contract version.
- **Contract test harness (F1):** `opis/json-schema` bound to the documents in `tamas-labs/aura-schema`
  without network calls; `assertMatchesAuraResponseSchema()` and `assertMatchesAuraRequestSchema()`
  test helpers; a named regression test that `simplePaginate()`'s `meta` does not satisfy the contract.
- **Query layer (F2):** `AuraRequest` (reading and validating the request, clamping `paginate`),
  `FieldPermissions` (field whitelist), `AuraQuery` (sorting, searching, filtering, global search,
  relations), and `AuraPayload` (`items` / `meta` / `links`).
- **Quality gate:** Pint (`laravel` preset), PHPStan/Larastan level `max`, and Pest.
  `composer quality` runs all three.
- **CI:** native PHP 8.3/8.4 × Laravel 12/13 matrix, plus a separate job that builds the development
  image and runs the gate in it.
- **Definition core (F3):** `AuraTable` (table as a class, with `respond()`), `Column` fluent
  builder, `ColumnGroup` (two-row header), `Footer`, `TableSettings`, `Inference` (column defaults
  from model casts), `Preset` + `Money` / `Timestamp` / `Options`, `AuraOption` enum interface,
  and `TableBlueprint` (definition and whitelist together, cacheable).
- **`header` and whitelist from one source.** `FieldPermissions` derives from the columns, from
  the cell array the browser also receives, following Aura's `reference || field || key` order. A
  hand-written header and manually maintained whitelist are no longer needed.
- **Build-time guards** (`InvalidDefinition`): duplicate column key, `fields` column without
  `reference`, `fields` column in global search, one-column group, fieldless cell without `colspan`,
  and table without columns.
- **README** in three files, following the workspace pattern: `README.md` (short, installation and
  basics), `README.en.md` and `README.hu.md` (full reference in English and Hungarian).
- **Cell builders (F4):** all nine `body.columnConfigs` types — `Text` (the contract's `static`; the
  `Static` word is reserved in PHP), `Reference`, `Badge`, `Link`, `Button`, `Icon`, `Modal`,
  `Progress`, `Custom` — split into shared traits (`HasFormatting`, `HasTypography`, `HasMapping`,
  `HasRoute`). They attach to a column with `->as()`, and to a multi-field column with `->configure()`.
- **Conditional configuration:** `Condition` with 19 operators (5 pure aliases among the contract's
  24 keys), `when()` / `otherwise()` / `on()`, and the same surface on `CellRules` for `<td>` and
  as `AuraTable::rowRules()` for `<tr>`.
- **`AuraVariant` and `AuraIcon`** enum interfaces alongside `AuraOption`, separately and optionally.
  `Badge::fromEnum()` reads all three and also produces a usable badge map from an enum implementing
  none of the interfaces.
- **Header formatting is inherited by the cell config.** With a renderer present, Aura passes only
  the config onward, so the column's `currency()` / `date()` / `slice()` settings would otherwise
  disappear silently. Explicit calls on the config still win.
- **`datetime()`, `time()`, and `raw()` on cell configs too**, following the column. The config
  schemas do not list them, but the renderer reads all three (`buildFormatConfig.ts`); until now
  they could only be inherited, not set or overridden.

### Fixed

- **A `Modal`'s nested trigger silently disappeared inside a conditional branch.** A branch goes
  through `settings()`, not `resolve()`, so `when(..., fn ($m) => $m->content(...))` emitted only
  the condition, not the trigger: the branch matched and changed nothing. Types are now prepared
  on branches as well.
- **`resolve()` no longer rewrites the builder it was called on.** `Modal` previously wrote the
  nested trigger into `$this` before the copy was made; the `prepare()` hook now runs on the copy.
- **A route-bearing `Icon` was not made into a link.** `renderIconNode` wraps the glyph in `<a>` only
  when both `route` *and* `key` are present (link, button, and modal need only route), while the
  package emitted `key` only for mapping. It now emits one for routes too, named after the route's
  first placeholder — or the column key without a placeholder, as Aura's preprocessor does.
- **In conditional configuration, the route key is placed in the branch.** Aura removes the root
  `key` (`stripLogicProps`) because it marks the condition field; a row condition therefore hid the
  linking icon correctly but rendered permitted rows without a link. Every leaf branch now receives
  its own key according to that branch's settings.

### Security

- Values of `field` received in the request reach the query exclusively through the `FieldPermissions`
  whitelist. Empty list = nothing permitted; there is no “allow everything” switch, and matching is
  exact (a prefix of an allowed name is not allowed).
- `paginate` is clamped to `aura.pagination.max`.
- `LIKE` search escapes `%` and `_` (`ESCAPE '!'`), so a `%` in the search field cannot turn the
  search into a full-table scan.
- `selected[]` never reaches the query.
- A cached definition cannot widen permissions: a non-array entry triggers a rebuild, and every
  non-string is discarded when the whitelist is read back.
- Every data column goes in the **last** row of `header.rows`. Aura takes columns only from there,
  so a `selectable` cell stranded in an earlier row would silently disable row selection (INV9).
- **`if` is never emitted without `key`.** Aura skips the conditions and applies the base
  configuration in that case — fail-open, and the wrong direction when the condition decides what
  is visible. If there is no field to use, the definition throws at build time (INV6, INV12).
- **Nesting conditions deeper than five levels throws at build time.** Aura silently truncates the
  configuration above `MAX_RECURSION_DEPTH` (INV5).
- **The route can only be relative.** Aura replaces every dot with a slash, so the absolute URL from
  `route()` would render as `/https://app/test/…`; both an absolute URL and a `{placeholder}` outside
  Aura's regex throw at build time (INV2).
- **Numerically compared fields are emitted as numbers.** Aura's `gt`/`lt`/`between` operators
  require `number`, while Laravel's `decimal` cast returns a string, so the condition would silently
  never match (INV11).
- **Renderers do not widen the whitelist.** A cell config says how a cell looks, never what the
  server accepts.
- **Structural keys are rejected by `set()`, not `merge()`.** Both paths end there: `merge()`
  delegates to `set()`, and `set()` is public on its own. The emitted configuration is assembled in
  `type + settings + conditionals` order, so a hand-written `key` would have defeated the one used
  for the conditionals — returning to the INV6/INV12 fail-open path.
- **Two columns cannot render the same field.** `columnConfigs` is one map keyed by field; the
  second entry replaces the first rather than joining it, and the losing column silently renders
  the winning configuration (INV10).

### Note

The contract schema documents **do not live in this repository**, but in the
[`tamas-labs/aura-schema`](https://github.com/tamas-labs/aura-schema) package, which is
`require-dev` — they do not reach package consumers. Since `aura-schema` is deliberately absent
from Packagist, `composer.json` pulls it through a `repositories` VCS entry as `dev-main`.
