<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;
use Yiisoft\Db\Expression\Expression;

/**
 * @api
 */
final readonly class DbIdempotencyStorage implements IdempotencyStorage
{
    /**
     * @param non-empty-string $table
     */
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private string $table = 'idempotency_keys',
    ) {}

    #[\Override]
    public function load(IdempotencyKey $key): ?IdempotencyRecord
    {
        $row = (new Query($this->db))
            ->from($this->table)
            ->where(condition: ['key' => $key->value])
            ->one();

        if ($row === null) {
            return null;
        }

        /** @var array<array-key, mixed> $row */
        $record = (new RecordRowMapper(clock: $this->clock))->map(row: $row);

        if ($record->isExpired($this->clock)) {
            $this->deleteByKey(key: $key);

            return null;
        }

        return $record;
    }

    #[\Override]
    public function claim(IdempotencyKey $key, IdempotencyFingerprint $fingerprint): bool
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        try {
            $affected = $this->db->createCommand()->insert(
                table: $this->table,
                columns: [
                    'key' => $key->value,
                    'fingerprint' => $fingerprint->hash,
                    'status_code' => 0,
                    'headers' => '{}',
                    'body' => '',
                    'expires_at' => $now,
                    'claimed' => 1,
                ],
            )->execute();

            return $affected > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    #[\Override]
    public function store(IdempotencyRecord $record): void
    {
        $headers = json_encode(value: $record->response->headers, flags: JSON_THROW_ON_ERROR);

        $this->db->createCommand()->update(
            table: $this->table,
            columns: [
                'fingerprint' => $record->fingerprint->hash,
                'status_code' => $record->response->statusCode,
                'headers' => $headers,
                'body' => $record->response->body,
                'expires_at' => $record->expiresAt->format('Y-m-d H:i:s'),
                'claimed' => 0,
            ],
            condition: ['key' => $record->key->value],
        )->execute();
    }

    #[\Override]
    public function release(IdempotencyKey $key): void
    {
        $this->deleteByKey(key: $key);
    }

    private function deleteByKey(IdempotencyKey $key): void
    {
        $this->db->createCommand()->delete(
            table: $this->table,
            condition: ['key' => $key->value],
        )->execute();
    }
}
