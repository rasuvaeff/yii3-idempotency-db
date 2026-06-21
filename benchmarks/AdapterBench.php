<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Benchmarks;

use Rasuvaeff\Yii3IdempotencyDb\RecordRowMapper;
use Testo\Bench;

final class AdapterBench
{
    #[Bench(
        callables: [
            'with-headers' => [self::class, 'mapWithHeaders'],
        ],
        calls: 1_000,
        iterations: 10,
    )]
    public static function mapMinimal(): mixed
    {
        return (new RecordRowMapper())->map([
            'key' => 'order-create-abc123',
            'fingerprint' => 'sha256:deadbeef',
            'status_code' => 201,
            'body' => '{"id":42}',
            'headers' => '{}',
            'expires_at' => '2025-01-01 00:00:00',
        ]);
    }

    public static function mapWithHeaders(): mixed
    {
        return (new RecordRowMapper())->map([
            'key' => 'order-create-abc123',
            'fingerprint' => 'sha256:deadbeef',
            'status_code' => 201,
            'body' => '{"id":42,"status":"created","total":199.99}',
            'headers' => '{"Content-Type":["application/json"],"X-Request-Id":["req-999"],"Cache-Control":["no-store"]}',
            'expires_at' => '2025-01-01 00:00:00',
        ]);
    }
}
