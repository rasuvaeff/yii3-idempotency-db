# Changelog

## Unreleased

### Fixed

- **Conditional deletes: the TTL boundary no longer allows a double execution.**
  `load()` used to delete a row it judged expired with an unconditional
  `DELETE ... WHERE key = :k`, which cannot tell that row from a fresh one a
  competitor created in between. Two concurrent retries could each delete the
  other's claim and both run the handler — the exact duplication this package
  exists to prevent. Expiration cleanup now also matches the `claimed` flag and
  an `expires_at <= now`; claim release matches only the exact `expires_at`
  this storage instance's own claim wrote — its ownership token, which a
  takeover necessarily replaces with a later one.
- **`store()` is fenced by the same ownership token.** The response used to be
  written with an unconditional upsert, so after a takeover the original
  handler's late store could overwrite the replacement claim — both responses
  delivered. The write is now a conditional `UPDATE` matching this instance's
  claim deadline; when that row is gone, the record is inserted only into an
  absent key, and losing the duplicate-key race to a competitor's newer claim
  leaves their row untouched.
- **The bundled migration no longer fails on MySQL.** `headers` and `body` were
  declared `text NOT NULL DEFAULT '…'`; MySQL rejects a literal DEFAULT on a
  TEXT column (error 1101), so `migrate:up` aborted having created nothing. The
  defaults are gone — every INSERT this package issues writes both columns
  explicitly, so nothing relied on them.
- **A binary response body no longer breaks `store()`.** A body that is not
  valid UTF-8 or holds a NUL byte (a PDF, a ZIP) is rejected by a PostgreSQL
  `text` column, and the failure landed after the handler's side effects were
  already committed — the client got a 500 and retried an operation that had in
  fact run. Such a body is now base64-encoded on write and decoded on read.

### Added

- `M260822000000AddBodyEncodingColumn`, **a required migration**: it adds the
  `body_encoding` column that every claim writes. Run `migrate:up` before
  deploying this version — see [UPGRADE.md](UPGRADE.md).
- `gcDivisor` constructor argument and param (default `1000`): roughly one
  successful claim in `gcDivisor` also runs `deleteExpired()`. Nothing else
  collected expired rows — an idempotency key is single-use, so the lazy cleanup
  in `load()` practically never fires for a given key and the table grew without
  bound unless the application wrote its own cron job. `gcDivisor: 0` keeps the
  old, purely manual behaviour.

## 2.0.2 — 2026-08-04

### Fixed

- Require `yiisoft/db-migration` ^2.1, which fixes `setSourceNamespaces()` matching a sibling namespace as a parent (upstream [yiisoft/db-migration#350](https://github.com/yiisoft/db-migration/pull/350)). Drop the manual `Injector::make()` migration workaround from both READMEs.

## 2.0.1 — 2026-08-01

- Docs: the documented `setSourceNamespaces()` migration registration does not
  find the bundled migration and never has — `yiisoft/db-migration` matches the
  PSR-4 map by string prefix and resolves into the core package, so
  `./yii migrate:up` exits 0 having created nothing. Both READMEs now say so and
  give a working `Injector`-based recipe until the upstream fix ships.

## 2.0.0 — 2026-07-25

**Breaking.** See [UPGRADE.md](UPGRADE.md) — an installation that already
applied the migration must rewrite one row in the `migration` table.

- The bundled migration moved to `Rasuvaeff\Yii3IdempotencyDb\Migration\M260611000000CreateIdempotencyKeysTable`
  (`src/Migration/`, PSR-4 autoloaded) from a global class in `migrations/`.
  Register it with `setSourceNamespaces()` instead of a `vendor/` path. Being
  autoloadable is what makes it safe to reference in DI at all: with the old
  global class, adding any container definition for it made
  `Yiisoft\Di\Container` fatal at build time in every request, because
  `new ReflectionClass()` ran before the migration runner had required the file.
- **The documented way to rename the table never worked.**
  `M...::class => ['__construct()' => ['table' => ...]]` is ignored:
  `yiisoft/db-migration` builds migrations through `Injector::make()`, which
  resolves arguments by name or type from the container and does not read
  definitions keyed by the migration's class — and a scalar `string $table` has
  no type to resolve. Users following the README silently got the default name.
- The table name is now a typed value object that `Injector` *can* resolve,
  built by `config/di.php` from params. One source of truth: the migration and
  `DbIdempotencyStorage` cannot disagree any more (in 1.x the runtime read params while the
  migration used its own default, so configuring params pointed the runtime at a
  table the migration had never created).
- New `table_prefix` param, prepended to `table` — a single place to keep
  package tables out of the way of an application's own.
- Index name is derived from the table name (`idx_<table>_expires_at`).
  Unchanged for the default table name; in PostgreSQL, where index names are
  unique per schema rather than per table, a hard-coded name collided between
  two installations sharing a schema.
- `DbIdempotencyStorage` validates the table name (through the same value
  object) — in 1.x it interpolated whatever string it was given straight into
  the query builder, with no identifier check at all.
- The row mapper's integer check is anchored with `\z` instead of `$`: PCRE's
  `$` also matches before a trailing newline.


## 1.0.1 — 2026-06-30

- Add `/benchmarks` and `/Makefile` to `.gitattributes` export-ignore.

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 1.0.1 — 2026-06-27

- Migrate test suite from PHPUnit to Testo. Internal change, no public API impact.

## 1.0.0 — 2026-06-12

- `DbIdempotencyStorage` — database-backed `IdempotencyStorage` for `rasuvaeff/yii3-idempotency`:
  atomic claim via `INSERT` (unique PK), in-flight claim deadline (`claimTtlSeconds`),
  stale-claim recovery, response replay, TTL expiration, `deleteExpired()` bulk cleanup.
- `RecordRowMapper` — strict row validation; invalid rows throw `InvalidRecordRowException`.
- Migration `M260611000000CreateIdempotencyKeysTable` for `yiisoft/db-migration`.
- Yii3 `config-plugin` wiring: binds `IdempotencyStorage` to `DbIdempotencyStorage`.
- All timestamps stored in UTC.

