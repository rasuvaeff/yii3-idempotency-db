<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\IntegrityException;
use Yiisoft\Db\Query\Query;

/**
 * @api
 */
final readonly class DbIdempotencyStorage implements IdempotencyStorage
{
    private const int MIN_CLAIM_TTL_SECONDS = 1;

    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param non-empty-string $table
     * @param int $claimTtlSeconds Deadline for an in-flight claim: a claimed row older
     * than this is treated as stale (crashed process) and may be re-claimed.
     */
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        private string $table = 'idempotency_keys',
        private int $claimTtlSeconds = 3600,
    ) {
        if ($claimTtlSeconds < self::MIN_CLAIM_TTL_SECONDS) {
            throw new \InvalidArgumentException('Claim TTL seconds must be greater than 0');
        }
    }

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
        $mapper = new RecordRowMapper();

        if ($this->isClaimedRow(row: $row)) {
            if ($this->clock->now() >= $mapper->expiresAt(row: $row)) {
                $this->deleteByKey(key: $key);
            }

            return null;
        }

        $record = $mapper->map(row: $row);

        if ($record->isExpired($this->clock)) {
            $this->deleteByKey(key: $key);

            return null;
        }

        return $record;
    }

    #[\Override]
    public function claim(IdempotencyKey $key, IdempotencyFingerprint $fingerprint): bool
    {
        $deadline = $this->clock->now()->modify("+{$this->claimTtlSeconds} seconds");

        try {
            $affected = $this->db->createCommand()->insert(
                table: $this->table,
                columns: [
                    'key' => $key->value,
                    'fingerprint' => $fingerprint->hash,
                    'status_code' => 0,
                    'headers' => '{}',
                    'body' => '',
                    'expires_at' => $this->formatDateTime($deadline),
                    'claimed' => 1,
                ],
            )->execute();

            return $affected > 0;
        } catch (IntegrityException) {
            return false;
        }
    }

    #[\Override]
    public function store(IdempotencyRecord $record): void
    {
        $headers = json_encode(value: $record->response->headers, flags: JSON_THROW_ON_ERROR);

        $this->db->createCommand()->upsert(
            table: $this->table,
            insertColumns: [
                'key' => $record->key->value,
                'fingerprint' => $record->fingerprint->hash,
                'status_code' => $record->response->statusCode,
                'headers' => $headers,
                'body' => $record->response->body,
                'expires_at' => $this->formatDateTime($record->expiresAt),
                'claimed' => 0,
            ],
        )->execute();
    }

    #[\Override]
    public function release(IdempotencyKey $key): void
    {
        $this->deleteByKey(key: $key);
    }

    public function deleteExpired(): int
    {
        return $this->db->createCommand()->delete(
            table: $this->table,
            condition: ['<=', 'expires_at', $this->formatDateTime($this->clock->now())],
        )->execute();
    }

    private function deleteByKey(IdempotencyKey $key): void
    {
        $this->db->createCommand()->delete(
            table: $this->table,
            condition: ['key' => $key->value],
        )->execute();
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function isClaimedRow(array $row): bool
    {
        return $this->toBool(value: $row['claimed'] ?? null);
    }

    private function toBool(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_int($value)) {
            return $value === 1;
        }

        if (\is_string($value)) {
            return $value === '1' || $value === 't' || $value === 'true';
        }

        return false;
    }

    private function formatDateTime(\DateTimeImmutable $dateTime): string
    {
        return $dateTime
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(self::DATETIME_FORMAT);
    }
}
