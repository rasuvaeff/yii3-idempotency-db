# AGENTS.md — yii3-idempotency-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

Database-backed idempotency storage for Yii3 APIs. Implements
`IdempotencyStorage` from `rasuvaeff/yii3-idempotency` core. Stores idempotency
records in a database table with atomic claim via `INSERT` (unique PK on `key`),
response replay through row mapping, and TTL-based expiration checked on `load()`.
A migration for `yiisoft/db-migration` ships in `src/Migration/`.

Namespace: `Rasuvaeff\Yii3IdempotencyDb`.
Public API: `DbIdempotencyStorage`, `Exception\InvalidRecordRowException`.
`RecordRowMapper` is `@internal` (row → `IdempotencyRecord` mapping, unit-tested directly).

## Golden rules

1. **Verification is mandatory.** Never claim "done" without a fresh green
   `composer build`. "Should work" does not count.
2. **No suppressions.** No `@psalm-suppress`, no baseline. Fix the root cause.
3. **Invalid row = exception.** Never silently skip or default invalid DB rows.
   Throw `InvalidRecordRowException` with a descriptive message.
4. **Preserve the public contract.** Update README + tests with any API change.

## Commands

No PHP/Composer on the host — run in Docker via the `composer:2` image.

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer psalm
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
```

Or with Make:

```bash
make build
make cs-fix
make psalm
make test
make test-coverage
make mutation
make release-check
```

`composer.lock` is gitignored (library).
`make test-coverage` and `make mutation` bootstrap `pcov` inside the
`composer:2` container because the base image has no coverage driver.

## Invariants & gotchas

- **The table name is a VO, not a string, because `Injector` cannot resolve a
  scalar.** `yiisoft/db-migration` builds migrations via `Injector::make()`,
  which resolves arguments by name or by type and never reads a container
  definition keyed by the migration's own class. That is why the 1.x recipe
  `M...::class => ['__construct()' => ['table' => …]]` silently did nothing —
  and why adding it made `Yiisoft\Di\Container` fatal at build time. Never
  reintroduce a scalar `string $table` on a migration.
- **One source of truth for the name.** `config/di.php` builds
  `IdempotencyKeysTableName` from `table_prefix` + `table` params and passes it
  to both the storage and the migration.
- **The index name is derived from the table name.** In PostgreSQL index names
  are unique per schema, not per table.
- Migrations live in `src/Migration/` and are therefore covered by cs, psalm and
  infection. `MigrationTableNameTest` asserts the column set and the index's
  columns — without it those `ArrayItemRemoval` mutants escape.
- `composer test` runs only the Unit suite; `composer mutation` runs every
  suite. An integration test left pointing at `migrations/` passes the first and
  fails the second.
- Identifier patterns are anchored with `\z`, not `$`.
- DB adapter is durable storage only — claim atomicity, response replay, TTL
  expiration, and conflict detection are guaranteed by the core middleware contract.
- `claim()` uses `INSERT` with unique PK for atomicity. Returns `false` ONLY on
  duplicate key (`IntegrityException`); any other DB error propagates — a DB
  outage must not look like an idempotency conflict.
- Claim rows carry `expires_at = now + claimTtlSeconds` (in-flight deadline).
  `load()` returns `null` for an active claim WITHOUT deleting the row; stale
  claims (deadline passed) are deleted and re-claimable.
- `store()` upserts the row with the full response data and sets `claimed = 0`.
- `load()` checks TTL; expired records are deleted and `null` is returned.
- Records are rehydrated via `IdempotencyRecord::restore()` (core >= 1.0 API);
  the constructor is private.
- All timestamps are formatted/parsed in UTC (`Y-m-d H:i:s`) — never rely on the
  PHP default timezone.
- `deleteExpired()` is the bulk GC entry point (uses the `expires_at` index).
- `release()` deletes the row (used on handler error to unclaim).
- Row → `IdempotencyRecord` mapping lives in `RecordRowMapper` (pure, unit-tested).
- The migration table name is a constructor argument. `setSourceNamespaces()`
  does NOT find them on any released `yiisoft/db-migration` (≤ 2.0.1): it
  matches the PSR-4 map by string prefix, so `Rasuvaeff\Yii3IdempotencyDb\Migration`
  resolves into the core package and discovery silently finds zero —
  `migrate:up` exits 0 having created nothing. Until an upstream release carries
  the fix, migrations are applied directly via
  `Injector::make($class)->up($builder)` — see the README.
- Invalid row / missing column / bad JSON headers → `InvalidRecordRowException`.
- Empty table or missing key → `null` (no exception).
- `key` is a SQL reserved word — always quoted in raw SQL.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` (and `examples/` if usage changed); update `CHANGELOG.md`
  when releasing.
- Re-run `composer build` and paste the output.
