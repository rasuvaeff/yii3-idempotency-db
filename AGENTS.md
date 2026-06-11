# AGENTS.md — yii3-idempotency-db

Guidance for AI agents working on this package. Read before changing code.

## What this is

Database-backed idempotency storage for Yii3 APIs. Implements
`IdempotencyStorage` from `rasuvaeff/yii3-idempotency` core. Stores idempotency
records in a database table with atomic claim via `INSERT` (unique PK on `key`),
response replay through row mapping, and TTL-based expiration checked on `load()`.
A migration for `yiisoft/db-migration` ships in `migrations/`.

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

- DB adapter is durable storage only — claim atomicity, response replay, TTL
  expiration, and conflict detection are guaranteed by the core middleware contract.
- `claim()` uses `INSERT` with unique PK for atomicity. Returns `false` on
  duplicate key or DB error.
- `store()` updates the claimed row with the full response data and sets
  `claimed = 0`.
- `load()` checks TTL; expired records are deleted and `null` is returned.
- `release()` deletes the row (used on handler error to unclaim).
- Row → `IdempotencyRecord` mapping lives in `RecordRowMapper` (pure, unit-tested).
- Migrations are loaded by `yiisoft/db-migration` via `sourcePaths` (global-namespace
  classes in `migrations/`); the migration table name is a constructor argument.
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
