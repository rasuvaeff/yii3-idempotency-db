<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyDb\IdempotencyKeysTableName;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

return [
    // the migration resolves this by type through Injector::make(), so the
    // storage and the migration can never disagree about the table
    IdempotencyKeysTableName::class => static function () use ($params): IdempotencyKeysTableName {
        $config = $params['rasuvaeff/yii3-idempotency-db'] ?? [];

        return new IdempotencyKeysTableName(
            ((string) ($config['table_prefix'] ?? '')) . ((string) ($config['table'] ?? 'idempotency_keys')),
        );
    },
    IdempotencyStorage::class => static function (
        ConnectionInterface $db,
        ClockInterface $clock,
        IdempotencyKeysTableName $table,
    ) use ($params): DbIdempotencyStorage {
        $config = $params['rasuvaeff/yii3-idempotency-db'] ?? [];

        return new DbIdempotencyStorage(
            db: $db,
            clock: $clock,
            table: $table->value,
            claimTtlSeconds: (int) ($config['claimTtlSeconds'] ?? 3600),
        );
    },
];
