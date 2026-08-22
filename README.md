# rasuvaeff/yii3-idempotency-db

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency-db.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-db)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency-db.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-db)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-db/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency-db/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-db/static-analysis.yml?branch=master&label=psalm)](https://github.com/rasuvaeff/yii3-idempotency-db/actions)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency-db.svg)](https://github.com/rasuvaeff/yii3-idempotency-db/blob/master/LICENSE.md)
[Русская версия](README.ru.md)

Database-backed idempotency storage for Yii3 APIs. Implements
`IdempotencyStorage` from `rasuvaeff/yii3-idempotency` with atomic claim
via `INSERT` (unique PK), response replay, and TTL-based expiration.

> Using an AI coding assistant? [llms.txt](llms.txt) contains a compact API reference
> you can paste into your prompt.

## Requirements

- PHP 8.3+
- `rasuvaeff/yii3-idempotency` ^1.0
- `yiisoft/db` ^2.0
- `yiisoft/db-migration` ^2.0
- `psr/clock` ^1.0

## Installation

```bash
composer require rasuvaeff/yii3-idempotency-db
```

## Usage

### Basic setup

```php
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Rasuvaeff\Yii3Idempotency\HeaderIdempotencyKeyExtractor;
use Rasuvaeff\Yii3Idempotency\IdempotencyMiddleware;

$storage = new DbIdempotencyStorage(
    db: $connection,           // yiisoft/db ConnectionInterface
    clock: $clock,             // PSR-20 ClockInterface
    table: 'idempotency_keys',
    claimTtlSeconds: 3600,     // deadline for in-flight claims (stale-claim recovery)
    gcDivisor: 1000,           // ~1 successful claim in 1000 also sweeps expired rows; 0 disables
);

$middleware = new IdempotencyMiddleware(
    keyExtractor: new HeaderIdempotencyKeyExtractor(),
    storage: $storage,
    responseFactory: $responseFactory,
    clock: $clock,
    ttlSeconds: 3600,
);
```

### Run migration

Register the bundled migration **by namespace** — no vendor paths:

```php
// config/common/di/migration.php
use Yiisoft\Db\Migration\Service\MigrationService;

return [
    MigrationService::class => [
        'setSourceNamespaces()' => [[
            'App\\Migration',
            'Rasuvaeff\\Yii3IdempotencyDb\\Migration',
        ]],
    ],
];
```

```bash
./yii migrate:up
./yii migrate:down --limit=1
```

The package ships two migrations, and **both are required**:
`M260611000000CreateIdempotencyKeysTable` creates the table, and
`M260822000000AddBodyEncodingColumn` adds the `body_encoding` column that every
claim writes. An installation created before that column existed must run
`migrate:up` before deploying this version — see [UPGRADE.md](UPGRADE.md).
Reverting the second one needs a driver that implements `DROP COLUMN`, which
`yiisoft/db-sqlite` does not.

`yiisoft/db-migration` resolves the migration through `Injector::make()`, so
it picks up the table-name value object from the container the same way the
storage does — no manual wiring needed beyond `setSourceNamespaces()` above.

Set the table name in params — `config/di.php` turns it into an
`IdempotencyKeysTableName` that reaches the migration **and**
`DbIdempotencyStorage`:

```php
// config/common/params.php
'rasuvaeff/yii3-idempotency-db' => [
    'table' => 'my_idempotency_keys',
    'table_prefix' => '',   // prepended to `table`; e.g. 'rsv_' → rsv_my_idempotency_keys
],
```

Index names follow the table name (idx_my_idempotency_keys_expires_at), so two installations can
share one PostgreSQL schema — index names are unique per schema there, not per
table.

> **Do not configure the migration through the DI container.**
> `M...::class => ['__construct()' => ['table' => ...]]` does not work: the
> migration is built by `Injector::make()`, which resolves arguments by type
> and never reads a container definition keyed by the migration's own class.
> Worse, adding that definition makes the container fatal at build time in
> **every** request, because the class is not autoloadable until the migration
> runner requires it. That recipe was documented in 1.x; it never worked.

### Table schema

| Column | Type | Description |
|---|---|---|
| `key` | `VARCHAR(255)` PK | Idempotency key value |
| `fingerprint` | `VARCHAR(64)` | SHA-256 hash of method + path + query + body |
| `status_code` | `SMALLINT` | HTTP response status code |
| `headers` | `TEXT` | JSON-encoded response headers (`array<string, list<string>>`) |
| `body` | `TEXT` | Response body (base64 when `body_encoding` says so) |
| `body_encoding` | `VARCHAR(16)` | `plain` or `base64` — how `body` is stored |
| `expires_at` | `VARCHAR(30)` | Expiration timestamp (UTC, `Y-m-d H:i:s`) |
| `claimed` | `BOOLEAN` | Whether the key is claimed (in-progress) |

`headers` and `body` deliberately carry no column DEFAULT: MySQL rejects a
literal DEFAULT on a TEXT column (error 1101), and nothing needs one — every
INSERT this package issues writes both columns explicitly.

### Yii3 integration

The package provides `config/di.php` and `config/params.php` for `yiisoft/config`.

Default params:

```php
// config/params.php
return [
    'rasuvaeff/yii3-idempotency-db' => [
        'table' => 'idempotency_keys',
        'claimTtlSeconds' => 3600,
        'gcDivisor' => 1000,
    ],
];
```

DI wiring binds `IdempotencyStorage::class` to `DbIdempotencyStorage`.

## How it works

1. **Claim**: `INSERT` with unique PK on `key` and `expires_at = now + claimTtlSeconds`.
   If the insert succeeds, the key is claimed atomically. A duplicate key raises a DB
   integrity error, which `claim()` converts to `false`; any other DB error propagates.
2. **Store**: After the handler completes, the response is written only into the
   claim this instance owns: a conditional `UPDATE` matching the key, `claimed = 1`
   and the exact `expires_at` this instance's claim wrote. If that row is gone — a
   takeover deleted it after the deadline passed — the record is inserted only when
   the key is absent; losing the duplicate-key race to a competitor's newer claim
   leaves their row untouched. `claimed` ends at `0` and `expires_at` becomes the
   record TTL deadline.
3. **Load**: On a subsequent request with the same key, `load()` reads the row.
   An active claim (`claimed = 1`, deadline not reached) returns `null` without
   deleting the row — the middleware then fails its own `claim()` and responds 409.
   A stale claim (deadline passed — crashed process) is deleted and may be re-claimed.
   A completed record is rehydrated via `IdempotencyRecord::restore()` and checked
   against its TTL; expired records are deleted.
4. **Release**: If the handler throws (or returns 5xx), `release()` deletes the
   claim row — but only the claim this storage instance took. The `expires_at`
   it wrote is the ownership token: a takeover after the claim went stale
   necessarily wrote a later deadline, so a late release from the previous owner
   matches no row and cannot delete either the competitor's claim or the
   response it has already stored.
5. **Cleanup**: `deleteExpired()` removes all rows past `expires_at` (uses the
   expiration index — `idx_idempotency_expires_at` on the default table,
   `idx_<table>_expires_at` for a custom one). Roughly one successful claim in
   `gcDivisor` runs it in-band, so the table does not grow without a cron job;
   set `gcDivisor: 0` to turn that off and drive the sweep yourself.

Every delete this package issues besides `deleteExpired()` matches an ownership
predicate, but the two kinds differ:

| Operation | Condition |
|---|---|
| Expiration cleanup (`load()`, stale-claim reclaim) | The `claimed` flag the caller read plus `expires_at <= now` — the row must still be the expired one it judged |
| Claim release (`release()`) | The **exact** `expires_at` this instance's own `claim()` wrote — the ownership token; a takeover necessarily wrote a later one |
| Response store (`store()`) | The same exact-deadline token on the `UPDATE`; the fallback INSERT only into an absent key |

An unconditional `DELETE WHERE key = :k` cannot tell the row the caller judged
from a fresh one a competitor created in between, so at the TTL boundary two
requests could each delete the other's claim and both run the handler.

## Security

- Idempotency keys are validated by core (`IdempotencyKey`).
- Fingerprints are SHA-256 hashes — no raw user input stored beyond the key.
- Response bodies are stored as-is; avoid storing sensitive data without encryption
  at the application layer.
- A body that is not valid UTF-8 (a PDF, a ZIP, anything with a NUL byte) is
  base64-encoded on write and decoded on read. A `text` column cannot hold those
  bytes — PostgreSQL rejects them — and failing there would fail a request whose
  side effects are already committed.
- All timestamps are stored in UTC — storage behavior does not depend on the PHP
  default timezone.

## Examples

See [examples/](examples/) for runnable scripts.

## Development

```bash
make install        # composer install
make build          # full gate (validate + normalize + cs + psalm + test)
make cs-fix         # fix code style
make psalm          # static analysis
make test           # run testo
make test-coverage  # testo with coverage
make mutation       # mutation testing
make release-check  # build + rector + bc-check + mutation
```

## License

BSD-3-Clause. See [LICENSE.md](LICENSE.md).
