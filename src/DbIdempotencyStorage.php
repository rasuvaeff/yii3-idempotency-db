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
    private string $table;

    private ClaimDeadlines $claimDeadlines;

    private const int MIN_CLAIM_TTL_SECONDS = 1;

    private const int DEFAULT_GC_DIVISOR = 1000;

    private const int GC_DISABLED = 0;

    /**
     * The single draw that triggers a sweep. A constant rather than a literal so
     * the lower bound and the comparison can never drift apart.
     */
    private const int GC_HIT = 1;

    private const string DATETIME_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param non-empty-string $table
     * @param int $claimTtlSeconds Deadline for an in-flight claim: a claimed row older
     * than this is treated as stale (crashed process) and may be re-claimed.
     * @param int $gcDivisor Roughly one successful claim in `gcDivisor` also sweeps expired
     * rows. Nothing else ever removes them — an idempotency key is single-use, so the lazy
     * cleanup on `load()` practically never fires. `0` turns the sweep off for deployments
     * that call {@see self::deleteExpired()} from their own cron job instead.
     */
    public function __construct(
        private ConnectionInterface $db,
        private ClockInterface $clock,
        string $table = 'idempotency_keys',
        private int $claimTtlSeconds = 3600,
        private int $gcDivisor = self::DEFAULT_GC_DIVISOR,
    ) {
        if ($claimTtlSeconds < self::MIN_CLAIM_TTL_SECONDS) {
            throw new \InvalidArgumentException('Claim TTL seconds must be greater than 0');
        }

        if ($gcDivisor < self::GC_DISABLED) {
            throw new \InvalidArgumentException('GC divisor must not be negative');
        }

        // validation lives in the value object, so the storage and the bundled
        // migration cannot disagree about what a valid table name is
        $this->table = (new IdempotencyKeysTableName($table))->value;
        $this->claimDeadlines = new ClaimDeadlines();
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
                $this->deleteExpiredRow(key: $key, claimed: true);
            }

            return null;
        }

        $record = $mapper->map(row: $row);

        if ($record->isExpired($this->clock)) {
            $this->deleteExpiredRow(key: $key, claimed: false);

            return null;
        }

        return $record;
    }

    #[\Override]
    public function claim(IdempotencyKey $key, IdempotencyFingerprint $fingerprint): bool
    {
        $deadline = $this->formatDateTime($this->clock->now()->modify("+{$this->claimTtlSeconds} seconds"));

        try {
            $this->db->createCommand()->insert(
                table: $this->table,
                columns: [
                    'key' => $key->value,
                    'fingerprint' => $fingerprint->hash,
                    'status_code' => 0,
                    'headers' => '{}',
                    'body' => '',
                    'body_encoding' => RecordRowMapper::ENCODING_PLAIN,
                    'expires_at' => $deadline,
                    'claimed' => 1,
                ],
            )->execute();
        } catch (IntegrityException) {
            // The unique PK on `key` is the atomicity: a duplicate means the
            // claim was taken (or the response already stored) by someone else.
            return false;
        }

        $this->claimDeadlines->remember($key->value, $deadline);
        $this->collectGarbage();

        return true;
    }

    /**
     * Writes the response only into the claim this instance owns.
     *
     * An unconditional upsert has no ownership predicate: after a takeover (the
     * claim deadline passed, a competitor deleted the stale row and claimed
     * fresh), the original handler's late store would overwrite the
     * replacement claim with a response its owner never wrote — and both
     * responses end up delivered, which is the duplication this package
     * exists to prevent. The update therefore matches `claimed = 1` and the
     * exact `expires_at` this instance's `claim()` wrote — the same ownership
     * token {@see self::release()} deletes by.
     *
     * When nothing matches, the claim row is gone (a takeover, or GC swept it)
     * or was never this instance's. The finished record is then written only
     * into an absent key: the INSERT either lands on the now-free key or loses
     * the duplicate-key race to a competitor's newer claim — which is left in
     * place untouched.
     */
    #[\Override]
    public function store(IdempotencyRecord $record): void
    {
        $headers = json_encode(value: $record->response->headers, flags: JSON_THROW_ON_ERROR);
        [$body, $encoding] = $this->encodeBody($record->response->body);

        // Reading and spending the token is one operation: a store that ran
        // twice must not fence twice.
        $deadline = $this->claimDeadlines->forget(key: $record->key->value);

        if ($deadline !== null) {
            $affected = $this->db->createCommand()->update(
                table: $this->table,
                columns: [
                    'status_code' => $record->response->statusCode,
                    'headers' => $headers,
                    'body' => $body,
                    'body_encoding' => $encoding,
                    'expires_at' => $this->formatDateTime($record->expiresAt),
                    'claimed' => 0,
                ],
                condition: [
                    'and',
                    ['key' => $record->key->value],
                    ['expires_at' => $deadline],
                ],
            )->execute();

            // Our claim row took the response; the token is already spent.
            if ($affected > 0) {
                return;
            }
        }

        try {
            $this->db->createCommand()->insert(
                table: $this->table,
                columns: [
                    'key' => $record->key->value,
                    'fingerprint' => $record->fingerprint->hash,
                    'status_code' => $record->response->statusCode,
                    'headers' => $headers,
                    'body' => $body,
                    'body_encoding' => $encoding,
                    'expires_at' => $this->formatDateTime($record->expiresAt),
                    'claimed' => 0,
                ],
            )->execute();
        } catch (IntegrityException) {
            // The claim row is gone (a takeover deleted it after the deadline
            // passed) and a competitor's newer claim — or their stored record —
            // owns the key now. Overwriting it is exactly what this fence
            // exists to prevent.
        }
    }

    #[\Override]
    public function release(IdempotencyKey $key): void
    {
        $deadline = $this->claimDeadlines->forget($key->value);

        // Only this instance's own claim, and only while it is still that claim.
        // `expires_at` is the ownership token: a takeover happens only after the
        // original deadline has passed and `claimTtlSeconds` is at least one
        // second, so the new owner necessarily wrote a later deadline and a late
        // release from the previous owner matches nothing. A release for a claim
        // this instance never took owns nothing to release, so it deletes
        // nothing — deleting whatever claim happens to sit under the key is how
        // a competitor's fresh claim used to disappear.
        if ($deadline !== null) {
            $this->db->createCommand()->delete(
                table: $this->table,
                condition: [
                    'and',
                    ['key' => $key->value],
                    ['claimed' => true],
                    ['expires_at' => $deadline],
                ],
            )->execute();
        }
    }

    public function deleteExpired(): int
    {
        return $this->db->createCommand()->delete(
            table: $this->table,
            condition: ['<=', 'expires_at', $this->formatDateTime($this->clock->now())],
        )->execute();
    }

    /**
     * Deletes a row only while it is still the one the caller judged expired.
     *
     * An unconditional `DELETE ... WHERE key = :k` cannot tell a row it just read
     * as expired from a fresh one a competitor created in between, so at the TTL
     * boundary two requests could each delete the other's claim and both run the
     * handler — the very duplication this package prevents.
     */
    private function deleteExpiredRow(IdempotencyKey $key, bool $claimed): void
    {
        $this->db->createCommand()->delete(
            table: $this->table,
            condition: [
                'and',
                ['key' => $key->value],
                ['claimed' => $claimed],
                ['<=', 'expires_at', $this->formatDateTime($this->clock->now())],
            ],
        )->execute();
    }

    private function collectGarbage(): void
    {
        if ($this->gcDivisor === self::GC_DISABLED) {
            return;
        }

        if (random_int(self::GC_HIT, $this->gcDivisor) !== self::GC_HIT) {
            return;
        }

        $this->deleteExpired();
    }

    /**
     * A `text` column cannot hold what is not valid UTF-8: PostgreSQL rejects an
     * invalid byte sequence and the NUL byte outright. Failing here would fail a
     * request whose side effects are already committed, so a binary body is
     * base64-encoded and the row records that.
     *
     * @return array{string, string}
     */
    private function encodeBody(string $body): array
    {
        if (preg_match('//u', $body) === 1 && !str_contains($body, "\0")) {
            return [$body, RecordRowMapper::ENCODING_PLAIN];
        }

        return [base64_encode($body), RecordRowMapper::ENCODING_BASE64];
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
