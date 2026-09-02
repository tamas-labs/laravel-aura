## What this changes

<!-- One or two sentences. Link the issue if there is one. -->

## Checklist

- [ ] `docker compose run --rm php composer quality` passes (see [CONTRIBUTING.md](../CONTRIBUTING.md) — there is no PHP on the host).
- [ ] A new public method is documented in **both** `README.en.md` and `README.hu.md`, or marked `@internal` — `tests/DocsCoverageTest.php` enforces the choice, and `tests/Docs/public-surface.txt` records the outcome.
- [ ] `CHANGELOG.md` has an `[Unreleased]` entry. No test enforces this one.
- [ ] The emitted JSON is unchanged, or the change is described above — the payload is public surface too, and a host application caches the definition.
