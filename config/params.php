<?php

declare(strict_types=1);

return [
    'rasuvaeff/yii3-idempotency-db' => [
        // one source of truth: both DbIdempotencyStorage and the bundled
        // migration read the resulting name through IdempotencyKeysTableName
        'table' => 'idempotency_keys',
        // prepended to `table`; set it once to keep every rasuvaeff table out
        // of the way of your application's own
        'table_prefix' => '',
        'claimTtlSeconds' => 3600,
        // roughly one successful claim in this many also sweeps expired rows;
        // an idempotency key is single-use, so nothing else removes them.
        // 0 turns the sweep off for a deployment that calls
        // DbIdempotencyStorage::deleteExpired() from its own cron job instead
        'gcDivisor' => 1000,
    ],
];
