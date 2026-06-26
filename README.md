# rasuvaeff/yii3-idempotency-db

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/yii3-idempotency-db.svg?label=stable)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-db)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/yii3-idempotency-db.svg)](https://packagist.org/packages/rasuvaeff/yii3-idempotency-db)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-db/build.yml?branch=master)](https://github.com/rasuvaeff/yii3-idempotency-db/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/yii3-idempotency-db/static-analysis.yml?branch=master&label=psalm)](https://github.com/rasuvaeff/yii3-idempotency-db/actions)
[![License](https://img.shields.io/packagist/l/rasuvaeff/yii3-idempotency-db.svg)](https://github.com/rasuvaeff/yii3-idempotency-db/blob/master/LICENSE.md)

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

```bash
yii migrate/up
```

Or use the migration class directly:

```php
use M260611000000CreateIdempotencyKeysTable;

$migration = new M260611000000CreateIdempotencyKeysTable(table: 'idempotency_keys');
$migration->up($builder);
```

### Table schema

| Column | Type | Description |
|---|---|---|
| `key` | `VARCHAR(255)` PK | Idempotency key value |
| `fingerprint` | `VARCHAR(64)` | SHA-256 hash of method + path + query + body |
| `status_code` | `SMALLINT` | HTTP response status code |
| `headers` | `TEXT` | JSON-encoded response headers (`array<string, list<string>>`) |
| `body` | `TEXT` | Response body |
| `expires_at` | `VARCHAR(30)` | Expiration timestamp (UTC, `Y-m-d H:i:s`) |
| `claimed` | `BOOLEAN` | Whether the key is claimed (in-progress) |

### Yii3 integration

The package provides `config/di.php` and `config/params.php` for `yiisoft/config`.

Default params:

```php
// config/params.php
return [
    'rasuvaeff/yii3-idempotency-db' => [
        'table' => 'idempotency_keys',
        'claimTtlSeconds' => 3600,
    ],
];
```

DI wiring binds `IdempotencyStorage::class` to `DbIdempotencyStorage`.

## How it works

1. **Claim**: `INSERT` with unique PK on `key` and `expires_at = now + claimTtlSeconds`.
   If the insert succeeds, the key is claimed atomically. A duplicate key raises a DB
   integrity error, which `claim()` converts to `false`; any other DB error propagates.
2. **Store**: After the handler completes, the response is upserted into the row
   and `claimed` is set to `0`; `expires_at` becomes the record TTL deadline.
3. **Load**: On a subsequent request with the same key, `load()` reads the row.
   An active claim (`claimed = 1`, deadline not reached) returns `null` without
   deleting the row — the middleware then fails its own `claim()` and responds 409.
   A stale claim (deadline passed — crashed process) is deleted and may be re-claimed.
   A completed record is rehydrated via `IdempotencyRecord::restore()` and checked
   against its TTL; expired records are deleted.
4. **Release**: If the handler throws (or returns 5xx), `release()` deletes the claim row.
5. **Cleanup**: `deleteExpired()` removes all rows past `expires_at` (uses the
   `idx_idempotency_expires_at` index) — call it from a cron task.

## Security

- Idempotency keys are validated by core (`IdempotencyKey`).
- Fingerprints are SHA-256 hashes — no raw user input stored beyond the key.
- Response bodies are stored as-is; avoid storing sensitive data without encryption
  at the application layer.
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
