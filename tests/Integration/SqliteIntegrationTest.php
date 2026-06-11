<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyDb\Exception\InvalidRecordRowException;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[CoversClass(DbIdempotencyStorage::class)]
final class SqliteIntegrationTest extends TestCase
{
    private ConnectionInterface $db;

    private ClockInterface $clock;

    private \DateTimeImmutable $now;

    #[\Override]
    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-11 12:00:00');
        $this->clock = $this->createStub(ClockInterface::class);
        $this->clock->method('now')->willReturnCallback(fn(): \DateTimeImmutable => $this->now);

        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->db->createCommand(sql: '
            CREATE TABLE idempotency_keys (
                "key"        VARCHAR(255) PRIMARY KEY,
                fingerprint  VARCHAR(64)  NOT NULL,
                status_code  INTEGER      NOT NULL DEFAULT 0,
                headers      TEXT         NOT NULL DEFAULT \'{}\',
                body         TEXT         NOT NULL DEFAULT \'\',
                expires_at   VARCHAR(30)  NOT NULL,
                claimed      INTEGER      NOT NULL DEFAULT 0
            )
        ')->execute();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->close();
    }

    #[Test]
    public function loadReturnsNullForMissingKey(): void
    {
        $storage = $this->createStorage();

        $result = $storage->load(key: new IdempotencyKey(value: 'missing-key'));

        $this->assertNull($result);
    }

    #[Test]
    public function claimInsertsRowAndReturnsTrue(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'order-123');
        $fingerprint = new IdempotencyFingerprint(hash: 'abc123');

        $result = $storage->claim(key: $key, fingerprint: $fingerprint);

        $this->assertTrue($result);

        $row = $this->fetchRow('order-123');
        $this->assertNotNull($row);
        $this->assertSame('abc123', $row['fingerprint']);
        $this->assertSame(1, (int) $row['claimed']);
    }

    #[Test]
    public function claimReturnsFalseForDuplicateKey(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'order-123');
        $fingerprint = new IdempotencyFingerprint(hash: 'abc123');

        $first = $storage->claim(key: $key, fingerprint: $fingerprint);
        $second = $storage->claim(key: $key, fingerprint: $fingerprint);

        $this->assertTrue($first);
        $this->assertFalse($second);
    }

    #[Test]
    public function storeUpdatesRecord(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'order-123');
        $fingerprint = new IdempotencyFingerprint(hash: 'abc123');

        $storage->claim(key: $key, fingerprint: $fingerprint);

        $record = IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: new IdempotencyResponse(
                statusCode: 200,
                headers: ['Content-Type' => ['application/json']],
                body: '{"status":"ok"}',
            ),
            expiresAt: $this->now->modify('+3600 seconds'),
        );

        $storage->store(record: $record);

        $row = $this->fetchRow('order-123');
        $this->assertNotNull($row);
        $this->assertSame(200, (int) $row['status_code']);
        $this->assertSame('{"status":"ok"}', $row['body']);
        $this->assertSame(0, (int) $row['claimed']);
    }

    #[Test]
    public function loadReturnsStoredRecord(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'order-456');
        $fingerprint = new IdempotencyFingerprint(hash: 'def456');

        $storage->claim(key: $key, fingerprint: $fingerprint);

        $record = IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: new IdempotencyResponse(
                statusCode: 201,
                headers: ['X-Request-Id' => ['req-1']],
                body: '{"id":1}',
            ),
            expiresAt: $this->now->modify('+3600 seconds'),
        );

        $storage->store(record: $record);

        $loaded = $storage->load(key: $key);

        $this->assertNotNull($loaded);
        $this->assertSame('order-456', $loaded->key->value);
        $this->assertSame('def456', $loaded->fingerprint->hash);
        $this->assertSame(201, $loaded->response->statusCode);
        $this->assertSame('{"id":1}', $loaded->response->body);
        $this->assertSame(['X-Request-Id' => ['req-1']], $loaded->response->headers);
    }

    #[Test]
    public function loadReturnsNullForExpiredRecord(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'expired-key');
        $fingerprint = new IdempotencyFingerprint(hash: 'expired-hash');

        $storage->claim(key: $key, fingerprint: $fingerprint);

        $record = IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: new IdempotencyResponse(
                statusCode: 200,
                headers: [],
                body: 'old-response',
            ),
            expiresAt: $this->now->modify('-1 second'),
        );

        $storage->store(record: $record);

        $this->now = $this->now->modify('+2 seconds');

        $loaded = $storage->load(key: $key);

        $this->assertNull($loaded);
    }

    #[Test]
    public function releaseDeletesRecord(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'to-release');
        $fingerprint = new IdempotencyFingerprint(hash: 'release-hash');

        $storage->claim(key: $key, fingerprint: $fingerprint);
        $storage->release(key: $key);

        $row = $this->fetchRow('to-release');
        $this->assertNull($row);
    }

    #[Test]
    public function releaseIsNoopForMissingKey(): void
    {
        $storage = $this->createStorage();

        $storage->release(key: new IdempotencyKey(value: 'never-existed'));

        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function usesCustomTableName(): void
    {
        $this->db->createCommand(sql: '
            CREATE TABLE custom_idempotency (
                "key"        VARCHAR(255) PRIMARY KEY,
                fingerprint  VARCHAR(64)  NOT NULL,
                status_code  INTEGER      NOT NULL DEFAULT 0,
                headers      TEXT         NOT NULL DEFAULT \'{}\',
                body         TEXT         NOT NULL DEFAULT \'\',
                expires_at   VARCHAR(30)  NOT NULL,
                claimed      INTEGER      NOT NULL DEFAULT 0
            )
        ')->execute();

        $storage = new DbIdempotencyStorage(
            db: $this->db,
            clock: $this->clock,
            table: 'custom_idempotency',
        );

        $key = new IdempotencyKey(value: 'custom-key');
        $fingerprint = new IdempotencyFingerprint(hash: 'custom-hash');

        $result = $storage->claim(key: $key, fingerprint: $fingerprint);

        $this->assertTrue($result);
    }

    #[Test]
    public function fullClaimStoreLoadCycle(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'cycle-123');
        $fingerprint = new IdempotencyFingerprint(hash: 'cycle-hash');

        $claimed = $storage->claim(key: $key, fingerprint: $fingerprint);
        $this->assertTrue($claimed);

        $record = IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: new IdempotencyResponse(
                statusCode: 200,
                headers: ['Content-Type' => ['application/json']],
                body: '{"result":"success"}',
            ),
            expiresAt: $this->now->modify('+3600 seconds'),
        );

        $storage->store(record: $record);

        $loaded = $storage->load(key: $key);

        $this->assertNotNull($loaded);
        $this->assertTrue($loaded->key->equals($key));
        $this->assertTrue($loaded->fingerprint->equals($fingerprint));
        $this->assertSame(200, $loaded->response->statusCode);
        $this->assertSame('{"result":"success"}', $loaded->response->body);
        $this->assertSame(['Content-Type' => ['application/json']], $loaded->response->headers);
    }

    #[Test]
    public function loadReturnsNullForActiveClaimWithoutDeletingIt(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'in-flight');
        $storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h1'));

        $this->assertNull($storage->load(key: $key));
        $this->assertNotNull($this->fetchRow('in-flight'));
        $this->assertFalse($storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h1')));
    }

    #[Test]
    public function staleClaimCanBeReclaimedAfterDeadline(): void
    {
        $storage = $this->createStorage(claimTtlSeconds: 60);

        $key = new IdempotencyKey(value: 'stale-claim');
        $storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h1'));

        $this->now = $this->now->modify('+61 seconds');

        $this->assertNull($storage->load(key: $key));
        $this->assertNull($this->fetchRow('stale-claim'));
        $this->assertTrue($storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h1')));
    }

    #[Test]
    public function claimPropagatesNonIntegrityErrors(): void
    {
        $storage = new DbIdempotencyStorage(
            db: $this->db,
            clock: $this->clock,
            table: 'missing_table',
        );

        $this->expectException(\Throwable::class);

        $storage->claim(
            key: new IdempotencyKey(value: 'any'),
            fingerprint: new IdempotencyFingerprint(hash: 'h1'),
        );
    }

    #[Test]
    public function storeInsertsWhenClaimRowIsGone(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'released-key');
        $fingerprint = new IdempotencyFingerprint(hash: 'h1');

        $record = IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: new IdempotencyResponse(statusCode: 200, headers: [], body: 'ok'),
            expiresAt: $this->now->modify('+3600 seconds'),
        );

        $storage->store(record: $record);

        $loaded = $storage->load(key: $key);

        $this->assertNotNull($loaded);
        $this->assertSame('ok', $loaded->response->body);
    }

    #[Test]
    public function deleteExpiredRemovesOnlyExpiredRows(): void
    {
        $storage = $this->createStorage(claimTtlSeconds: 60);

        $storage->claim(
            key: new IdempotencyKey(value: 'old-claim'),
            fingerprint: new IdempotencyFingerprint(hash: 'h1'),
        );
        $storage->store(record: IdempotencyRecord::restore(
            key: new IdempotencyKey(value: 'old-record'),
            fingerprint: new IdempotencyFingerprint(hash: 'h2'),
            response: new IdempotencyResponse(statusCode: 200, headers: [], body: 'ok'),
            expiresAt: $this->now->modify('+30 seconds'),
        ));
        $storage->store(record: IdempotencyRecord::restore(
            key: new IdempotencyKey(value: 'fresh-record'),
            fingerprint: new IdempotencyFingerprint(hash: 'h3'),
            response: new IdempotencyResponse(statusCode: 200, headers: [], body: 'ok'),
            expiresAt: $this->now->modify('+7200 seconds'),
        ));

        $this->now = $this->now->modify('+90 seconds');

        $deleted = $storage->deleteExpired();

        $this->assertSame(2, $deleted);
        $this->assertNull($this->fetchRow('old-claim'));
        $this->assertNull($this->fetchRow('old-record'));
        $this->assertNotNull($this->fetchRow('fresh-record'));
    }

    #[Test]
    public function rejectsNonPositiveClaimTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new DbIdempotencyStorage(
            db: $this->db,
            clock: $this->clock,
            claimTtlSeconds: 0,
        );
    }

    #[Test]
    public function loadThrowsOnInvalidRowData(): void
    {
        $this->db->createCommand(sql: "
            INSERT INTO idempotency_keys (\"key\", fingerprint, status_code, headers, body, expires_at, claimed)
            VALUES ('bad-key', 'hash', 'not-a-number', '{}', 'body', '2026-06-12 00:00:00', 0)
        ")->execute();

        $storage = $this->createStorage();

        $this->expectException(InvalidRecordRowException::class);

        $storage->load(key: new IdempotencyKey(value: 'bad-key'));
    }

    private function createStorage(int $claimTtlSeconds = 3600): DbIdempotencyStorage
    {
        return new DbIdempotencyStorage(
            db: $this->db,
            clock: $this->clock,
            claimTtlSeconds: $claimTtlSeconds,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchRow(string $key): ?array
    {
        $rows = $this->db->createCommand(sql: "
            SELECT * FROM idempotency_keys WHERE \"key\" = :key
        ")->bindValues([':key' => $key])->queryAll();

        if ($rows === []) {
            return null;
        }

        return $rows[0];
    }
}
