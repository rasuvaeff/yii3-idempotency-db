<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests\Integration;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyDb\Exception\InvalidRecordRowException;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[Covers(DbIdempotencyStorage::class)]
final class SqliteIntegrationTest
{
    private ConnectionInterface $db;

    private ClockInterface $clock;

    private \DateTimeImmutable $now;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-06-11 12:00:00');
        $now = &$this->now;
        $this->clock = new class ($now) implements ClockInterface {
            public function __construct(
                private \DateTimeImmutable &$now,
            ) {}

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };

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

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function loadReturnsNullForMissingKey(): void
    {
        $storage = $this->createStorage();

        $result = $storage->load(key: new IdempotencyKey(value: 'missing-key'));

        Assert::null($result);
    }

    public function claimInsertsRowAndReturnsTrue(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'order-123');
        $fingerprint = new IdempotencyFingerprint(hash: 'abc123');

        $result = $storage->claim(key: $key, fingerprint: $fingerprint);

        Assert::true($result);

        $row = $this->fetchRow('order-123');
        Assert::notNull($row);
        Assert::same($row['fingerprint'], 'abc123');
        Assert::same((int) $row['claimed'], 1);
    }

    public function claimReturnsFalseForDuplicateKey(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'order-123');
        $fingerprint = new IdempotencyFingerprint(hash: 'abc123');

        $first = $storage->claim(key: $key, fingerprint: $fingerprint);
        $second = $storage->claim(key: $key, fingerprint: $fingerprint);

        Assert::true($first);
        Assert::false($second);
    }

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
        Assert::notNull($row);
        Assert::same((int) $row['status_code'], 200);
        Assert::same($row['body'], '{"status":"ok"}');
        Assert::same((int) $row['claimed'], 0);
    }

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

        Assert::notNull($loaded);
        Assert::same($loaded->key->value, 'order-456');
        Assert::same($loaded->fingerprint->hash, 'def456');
        Assert::same($loaded->response->statusCode, 201);
        Assert::same($loaded->response->body, '{"id":1}');
        Assert::same($loaded->response->headers, ['X-Request-Id' => ['req-1']]);
    }

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

        Assert::null($loaded);
    }

    public function releaseDeletesRecord(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'to-release');
        $fingerprint = new IdempotencyFingerprint(hash: 'release-hash');

        $storage->claim(key: $key, fingerprint: $fingerprint);
        $storage->release(key: $key);

        $row = $this->fetchRow('to-release');
        Assert::null($row);
    }

    public function releaseIsNoopForMissingKey(): void
    {
        $storage = $this->createStorage();

        $storage->release(key: new IdempotencyKey(value: 'never-existed'));

        Assert::true(true);
    }

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

        Assert::true($result);
    }

    public function fullClaimStoreLoadCycle(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'cycle-123');
        $fingerprint = new IdempotencyFingerprint(hash: 'cycle-hash');

        $claimed = $storage->claim(key: $key, fingerprint: $fingerprint);
        Assert::true($claimed);

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

        Assert::notNull($loaded);
        Assert::true($loaded->key->equals($key));
        Assert::true($loaded->fingerprint->equals($fingerprint));
        Assert::same($loaded->response->statusCode, 200);
        Assert::same($loaded->response->body, '{"result":"success"}');
        Assert::same($loaded->response->headers, ['Content-Type' => ['application/json']]);
    }

    public function loadReturnsNullForActiveClaimWithoutDeletingIt(): void
    {
        $storage = $this->createStorage();

        $key = new IdempotencyKey(value: 'in-flight');
        $storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h1'));

        Assert::null($storage->load(key: $key));
        Assert::notNull($this->fetchRow('in-flight'));
        Assert::false($storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h1')));
    }

    public function staleClaimCanBeReclaimedAfterDeadline(): void
    {
        $storage = $this->createStorage(claimTtlSeconds: 60);

        $key = new IdempotencyKey(value: 'stale-claim');
        $storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h1'));

        $this->now = $this->now->modify('+61 seconds');

        Assert::null($storage->load(key: $key));
        Assert::null($this->fetchRow('stale-claim'));
        Assert::true($storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h1')));
    }

    public function staleClaimAtExactDeadlineIsReclaimable(): void
    {
        $storage = $this->createStorage(claimTtlSeconds: 60);
        $key = new IdempotencyKey(value: 'edge-claim');
        $storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h1'));

        $this->now = $this->now->modify('+60 seconds');

        Assert::null($storage->load(key: $key));
        Assert::true($storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'h2')));
    }

    public function allowsClaimTtlOfOne(): void
    {
        $storage = new DbIdempotencyStorage(db: $this->db, clock: $this->clock, claimTtlSeconds: 1);

        Assert::true($storage->claim(
            key: new IdempotencyKey(value: 'ttl-one'),
            fingerprint: new IdempotencyFingerprint(hash: 'h1'),
        ));
    }

    public function claimRowStoresZeroStatusCode(): void
    {
        $this->createStorage()->claim(
            key: new IdempotencyKey(value: 'zero-status'),
            fingerprint: new IdempotencyFingerprint(hash: 'h1'),
        );

        $row = $this->fetchRow('zero-status');
        Assert::notNull($row);
        Assert::same((int) $row['status_code'], 0);
    }

    public function claimPropagatesNonIntegrityErrors(): void
    {
        $storage = new DbIdempotencyStorage(
            db: $this->db,
            clock: $this->clock,
            table: 'missing_table',
        );

        Expect::exception(\Throwable::class);

        $storage->claim(
            key: new IdempotencyKey(value: 'any'),
            fingerprint: new IdempotencyFingerprint(hash: 'h1'),
        );
    }

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

        Assert::notNull($loaded);
        Assert::same($loaded->response->body, 'ok');
    }

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

        Assert::same($deleted, 2);
        Assert::null($this->fetchRow('old-claim'));
        Assert::null($this->fetchRow('old-record'));
        Assert::notNull($this->fetchRow('fresh-record'));
    }

    public function rejectsNonPositiveClaimTtl(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        new DbIdempotencyStorage(
            db: $this->db,
            clock: $this->clock,
            claimTtlSeconds: 0,
        );
    }

    public function loadThrowsOnInvalidRowData(): void
    {
        $this->db->createCommand(sql: "
            INSERT INTO idempotency_keys (\"key\", fingerprint, status_code, headers, body, expires_at, claimed)
            VALUES ('bad-key', 'hash', 'not-a-number', '{}', 'body', '2026-06-12 00:00:00', 0)
        ")->execute();

        $storage = $this->createStorage();

        Expect::exception(InvalidRecordRowException::class);

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
