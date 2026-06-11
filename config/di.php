<?php

declare(strict_types=1);

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Yiisoft\Db\Connection\ConnectionInterface;

/** @var array $params */

return [
    IdempotencyStorage::class => static function (
        ConnectionInterface $db,
        ClockInterface $clock,
    ) use ($params): DbIdempotencyStorage {
        $config = $params['rasuvaeff/yii3-idempotency-db'] ?? [];

        return new DbIdempotencyStorage(
            db: $db,
            clock: $clock,
            table: $config['table'] ?? 'idempotency_keys',
        );
    },
];
