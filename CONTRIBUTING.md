# Contributing to laravel-aura

Thanks for your interest. This guide covers the local setup, the gates a change has to pass, and
how a release is cut.

## Everything runs in Docker

**There is deliberately no PHP and no Composer on the host.** Every command is prefixed:

```bash
docker compose run --rm php composer install     # install dependencies
docker compose run --rm php composer quality     # pint + phpstan + pest — what CI runs
docker compose run --rm php vendor/bin/pest      # the test suite
docker compose run --rm php vendor/bin/pest --filter "clamps paginate"
docker compose run --rm php composer test:coverage   # the suite with its floor
docker compose run --rm php vendor/bin/pint      # apply formatting
docker compose run --rm php composer bc-check    # API breaks against the last release
```

One service, `php`, on `php:8.4-cli-alpine`. There is **no database container** — the suite runs on
in-memory SQLite, so `docker compose up` is never needed. The image runs as uid/gid 1000 so files
written inside stay editable outside; override with the `UID`/`GID` build args if your host user
differs.

`README.en.md` (or `README.hu.md`) is the full reference for what the package does. This file is
only about working on it.

## The gate

`composer quality` is Laravel Pint (`laravel` preset), PHPStan/Larastan at level **max** over
`src/`, `tests/` and `workbench/`, and Pest. CI runs the same thing across PHP 8.3 / 8.4 / 8.5 ×
Laravel 12 / 13, measures coverage on one leg with a `--min=90` floor, and separately builds the
development image so the Dockerfile cannot rot.

Run it before opening a pull request. It is fast, and it is the same command CI runs.

## What a change has to carry

Several rules in this repository are enforced by tests that name the offending file and line. They
are worth knowing before you write the code rather than after:

- **A new public method is documented twice, or it is `@internal`.** `tests/DocsCoverageTest.php`
  reflects over `src/` and fails when a public method is mentioned in neither full reference — or
  in only one of the two, which is how the English and the Hungarian drift apart. `@internal` is
  the way out, and it is the same tag the version promise reads: a method excluded from the count
  is a method semver does not cover.
- **The covered set is recorded.** `tests/Docs/public-surface.txt` lists every method the promise
  covers, and `tests/PublicSurfaceTest.php` rebuilds it and fails on any difference — in either
  direction, because a method that appeared is a minor release and one that vanished is a major
  one. Regenerating it means editing it; the failure prints the exact lines.
- **`CHANGELOG.md` gets an `[Unreleased]` entry.** No test enforces this one.
- **The emitted JSON is public surface too.** A change that leaves every call identical and alters
  what the browser draws is a breaking change: the payload is this package's output, and a host
  application usually caches the definition, so it cannot see it happen.
- **The wire contract is not in this repository.** It lives in
  [tamas-labs/aura-schema](https://github.com/tamas-labs/aura-schema) and arrives as a dev
  dependency. Never copy a schema document in here, and never edit one under `vendor/` — a change
  to the contract is a pull request there, and `tests/ContractSchemaTest.php` fails if the two
  versions disagree.
- **Tests are Pest, colocated under `tests/`.** A feature or a fix is expected to ship one. The
  contract tests use the plain helpers `assertMatchesAuraResponseSchema()` /
  `assertMatchesAuraRequestSchema()`, and fixtures are decoded with `auraJsonFile()` — which
  decodes to objects, because `json_decode(…, true)` erases the difference between an empty object
  and an empty array and JSON Schema cares.

## Conventions

- PHP 8.3+, `declare(strict_types=1)` in every file (Pint enforces it).
- Namespace `TamasLabs\Aura\`, PSR-4 from `src/`.
- **Code comments in English**, in every version-controlled file. The maintainer's working language
  is Hungarian for discussion, commit messages, `README.hu.md` and `CHANGELOG.md`; a pull request
  in English is equally welcome.
- Keep a pull request to one feature or fix.

## Release process

Releases are cut from `main`, and the workflow is the last gate — not the publisher. A Composer
package is installed from its git tag, so **the tag is the release**: by the time the workflow
runs, it is already public. There is no publish step to abort, which is exactly why the tag is the
last thing to happen.

1. Move the `CHANGELOG.md` `[Unreleased]` section into a new `## [X.Y.Z] - YYYY-MM-DD` heading.
2. Commit and push to `main`. Wait for CI — including the backward compatibility job, which
   compares `src/` against the last tagged minor version and is the reason to look before tagging
   rather than after.
3. `git tag vX.Y.Z && git push origin vX.Y.Z`.
4. The [`Release`](./.github/workflows/release.yml) workflow re-runs the gate with its coverage
   variant, refuses a tag with no dated changelog section of its own, and creates a GitHub Release
   whose notes are that section.

`composer.json` deliberately carries **no `version` key** — a second source of truth for something
the tag already says. The changelog is what a tag is checked against instead.

## Security

See [SECURITY.md](./SECURITY.md). Please do not open a public issue for a vulnerability.
