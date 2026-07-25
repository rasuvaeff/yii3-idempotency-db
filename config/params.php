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
    ],
];
