# Security Policy

## Supported versions

Only the latest released minor version receives security fixes. There is no long-term support
branch, and no release has been cut yet — until the first tag, `main` is the only thing there is.

## Reporting a vulnerability

Please **do not** open a public issue.

- [Open a private security advisory](https://github.com/tamas-labs/laravel-aura/security/advisories/new)
  (preferred)
- Or email tbalint78@gmail.com

Please include the table definition, the request that triggered it, and the payload or query you
got out — the same three things a bug report needs. A proof of concept is worth more than a
description here, because most of this package's surface is a builder API where the interesting
question is what reaches the query.

You should get a response within a few days. A confirmed vulnerability is fixed, disclosed through
a GitHub Security Advisory, and recorded in `CHANGELOG.md` when the fix is released.

## What the boundary is

Two rules carry most of the weight, and a report that crosses either of them is a vulnerability
rather than a bug:

- **`FieldPermissions` is the only way a client field reaches the query.** Every `field` in a
  request is attacker controlled; an unlisted one is a 422, never a silently ignored parameter. An
  empty list allows nothing, matching is exact, and there is deliberately no "allow everything"
  switch. A field that reaches sorting, searching or filtering without appearing in the whitelist
  the columns derive is the thing to report.
- **`AuraQuery::likeExpression()` is the only raw SQL in the package.** Everything else goes
  through the query builder with bindings. A search term is never interpolated.

Two things are deliberately **not** security boundaries, and are documented as such:

- **`allowedWhen()` hides a cell; it does not authorise anything.** The row, its identifier and the
  route all stay in the payload. Protect the route with the policy it deserves.
- **The generated payload is public data.** Whatever a column emits is sent to the browser, cached
  by the host application, and visible to anyone who can open the table.
