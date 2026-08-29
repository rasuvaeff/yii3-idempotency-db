# AGENTS.md — yii3-idempotency-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

Database-backed idempotency storage for Yii3 APIs. Implements
`IdempotencyStorage` from `rasuvaeff/yii3-idempotency` core. Stores idempotency
records in a database table with atomic claim via `INSERT` (unique PK on `key`),
response replay through row mapping, and TTL-based expiration checked on `load()`.
A migration for `yiisoft/db-migration` ships in `src/Migration/`.

Namespace: `Rasuvaeff\Yii3IdempotencyDb`.
Public API: `DbIdempotencyStorage`, `Exception\InvalidRecordRowException`, and the
two migrations in `Migration\`.
`RecordRowMapper` and `ClaimDeadlines` are `@internal` (row → `IdempotencyRecord`
mapping and the per-instance claim-ownership map, both unit-tested directly).

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
- `claimedFingerprint()` implements the core's `ClaimedFingerprintProvider`
  (core ^2.1): a pure read of the `fingerprint` column over an active claim
  (`claimed = 1`) — a finished record answers `null`. The middleware uses it
  to answer 422 instead of a retryable 409 on an in-flight payload mismatch;
  keep it a read with no side effects, exactly like `load()`'s stale-claim
  cleanup stays in `load()`.
- `store()` upserts the row with the full response data and sets `claimed = 0`.
- `load()` checks TTL; expired records are deleted and `null` is returned.
- **Every delete except `deleteExpired()` is conditional.** A delete matches the
  `claimed` flag the caller read plus `expires_at <= now`; `release()` matches the
  exact `expires_at` its own `claim()` wrote (remembered in `ClaimDeadlines`).
  An unconditional `DELETE WHERE key = :k` cannot tell the row the caller judged
  from a fresh one a competitor created in between — that is how the TTL boundary
  used to allow two handlers to run. Never widen these conditions.
- **`store()` is fenced by the same ownership token.** The response write is a
  conditional `UPDATE` matching `key` + `claimed = 1` + the exact claim deadline;
  when nothing matches (a takeover deleted the stale row), the record goes in as
  a plain INSERT that loses the duplicate-key race to any newer row silently.
  An unconditional upsert here let the slow original handler overwrite the
  replacement claim after a takeover — both responses delivered. Reading and
  spending the token is one `ClaimDeadlines::forget()` call; never replace the
  fence with an upsert.
- **A body that is not valid UTF-8, or holds a NUL byte, is base64-encoded** and
  the row's `body_encoding` says so; a `text` column cannot hold those bytes
  (PostgreSQL rejects them) and failing in `store()` fails a request whose side
  effects are already committed.
- **No literal DEFAULT on a TEXT column.** MySQL rejects it outright (error 1101)
  and `migrate:up` aborts having created nothing. VARCHAR defaults are fine.
- Roughly one successful `claim()` in `gcDivisor` also runs `deleteExpired()`;
  `gcDivisor: 0` disables the in-band sweep. Tests construct the storage with
  `gcDivisor: 0` unless the sweep itself is under test — `1` makes it fire on
  every claim, deterministically.
- Records are rehydrated via `IdempotencyRecord::restore()` (core >= 1.0 API);
  the constructor is private.
- All timestamps are formatted/parsed in UTC (`Y-m-d H:i:s`) — never rely on the
  PHP default timezone.
- `deleteExpired()` is the bulk GC entry point (uses the `expires_at` index).
- `release()` deletes the claim row this instance took (used on handler error to
  unclaim); it is a no-op against a finished record or a claim someone else owns.
- Row → `IdempotencyRecord` mapping lives in `RecordRowMapper` (pure, unit-tested).
- Both migrations are required; `M260822000000AddBodyEncodingColumn` adds the
  column `claim()` writes, and its `down()` needs a driver with `DROP COLUMN`
  (SQLite has none, so its revert test asserts the `NotSupportedException`).
- The migration table name is a constructor argument, resolved by
  `Injector::make()` the same way as the storage. `setSourceNamespaces()`
  registration works as of `yiisoft/db-migration` ^2.1 — see the README.
- Invalid row / missing column / bad JSON headers → `InvalidRecordRowException`.
- Empty table or missing key → `null` (no exception).
- `key` is a SQL reserved word — always quoted in raw SQL.
- Code: `declare(strict_types=1)`, `final readonly class`, `#[\Override]`,
  explicit types.
- `examples/` is part of the public contract: keep scripts runnable and update
  `examples/README.md` when example usage changes.

## When you finish

- Update `README.md` **and `README.ru.md`** (both languages, same commit; and
  `examples/` if usage changed); update `CHANGELOG.md` when releasing.
- Re-run `composer build` and paste the output.
