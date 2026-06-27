# Changelog

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

