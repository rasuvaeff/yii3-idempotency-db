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
use Testo\Data\DataProvider;
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
#[Covers(InvalidRecordRowException::class)]
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
                headers      TEXT         NOT NULL,
                body         TEXT         NOT NULL,
                body_encoding VARCHAR(16) NOT NULL DEFAULT \'plain\',
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

    public function loadFiltersByKeyAmongMultipleRecords(): void
    {
        $storage = $this->createStorage();

        $keyA = new IdempotencyKey(value: 'multi-a');
        $fingerprintA = new IdempotencyFingerprint(hash: 'fp-a');
        $storage->claim(key: $keyA, fingerprint: $fingerprintA);
        $storage->store(record: IdempotencyRecord::restore(
            key: $keyA,
            fingerprint: $fingerprintA,
            response: new IdempotencyResponse(statusCode: 200, headers: [], body: 'body-a'),
            expiresAt: $this->now->modify('+3600 seconds'),
        ));

        $keyB = new IdempotencyKey(value: 'multi-b');
        $fingerprintB = new IdempotencyFingerprint(hash: 'fp-b');
        $storage->claim(key: $keyB, fingerprint: $fingerprintB);
        $storage->store(record: IdempotencyRecord::restore(
            key: $keyB,
            fingerprint: $fingerprintB,
            response: new IdempotencyResponse(statusCode: 201, headers: [], body: 'body-b'),
            expiresAt: $this->now->modify('+3600 seconds'),
        ));

        $loaded = $storage->load(key: $keyB);

        Assert::notNull($loaded);
        Assert::same($loaded->key->value, 'multi-b');
        Assert::same($loaded->response->body, 'body-b');
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
        Assert::null($this->fetchRow('expired-key'));
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

    public function releaseDeletesOnlyMatchingKey(): void
    {
        $storage = $this->createStorage();

        $keyA = new IdempotencyKey(value: 'release-a');
        $storage->claim(key: $keyA, fingerprint: new IdempotencyFingerprint(hash: 'h1'));

        $keyB = new IdempotencyKey(value: 'release-b');
        $storage->claim(key: $keyB, fingerprint: new IdempotencyFingerprint(hash: 'h2'));

        $storage->release(key: $keyA);

        Assert::null($this->fetchRow('release-a'));
        Assert::notNull($this->fetchRow('release-b'));
    }

    public function releaseIsNoopForMissingKey(): void
    {
        $storage = $this->createStorage();

        $storage->release(key: new IdempotencyKey(value: 'never-existed'));

        Assert::true(actual: true);
    }

    public function usesCustomTableName(): void
    {
        $this->db->createCommand(sql: '
            CREATE TABLE custom_idempotency (
                "key"        VARCHAR(255) PRIMARY KEY,
                fingerprint  VARCHAR(64)  NOT NULL,
                status_code  INTEGER      NOT NULL DEFAULT 0,
                headers      TEXT         NOT NULL,
                body         TEXT         NOT NULL,
                body_encoding VARCHAR(16) NOT NULL DEFAULT \'plain\',
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

    #[DataProvider('stringClaimedFlagProvider')]
    public function loadTreatsStringClaimedFlagAsActive(string $claimedValue): void
    {
        $this->db->createCommand(sql: '
            INSERT INTO idempotency_keys ("key", fingerprint, status_code, headers, body, expires_at, claimed)
            VALUES (:key, :fingerprint, :status_code, :headers, :body, :expires_at, :claimed)
        ')->bindValues([
            ':key' => 'str-claimed',
            ':fingerprint' => 'hash',
            ':status_code' => 200,
            ':headers' => '{}',
            ':body' => 'body',
            ':expires_at' => $this->now->modify('+3600 seconds')->format('Y-m-d H:i:s'),
            ':claimed' => $claimedValue,
        ])->execute();

        $storage = $this->createStorage();

        Assert::null($storage->load(key: new IdempotencyKey(value: 'str-claimed')));
        Assert::notNull($this->fetchRow('str-claimed'));
    }

    public static function stringClaimedFlagProvider(): iterable
    {
        yield 't' => ['t'];
        yield 'true' => ['true'];
    }

    public function releaseKeepsAClaimTakenOverAfterItWentStale(): void
    {
        // the fencing property: the deadline a claim wrote is its ownership
        // token, and a takeover necessarily writes a later one, so a release
        // arriving from the previous owner matches nothing
        $key = new IdempotencyKey(value: 'fenced');
        $first = $this->createStorage(claimTtlSeconds: 60);
        $first->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'first'));

        $this->now = $this->now->modify('+61 seconds');

        $second = $this->createStorage(claimTtlSeconds: 60);
        Assert::null($second->load(key: $key));
        Assert::true($second->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'second')));

        $first->release(key: $key);

        $row = $this->fetchRow('fenced');
        Assert::notNull($row);
        Assert::same($row['fingerprint'], 'second');
    }

    public function releaseOwnsNothingItDidNotClaim(): void
    {
        // a release from a process that never took this claim — a retry that
        // failed before claiming, a storage rebuilt mid-request — must not
        // delete the claim whoever did take it is working under
        $key = new IdempotencyKey(value: 'not-mine');
        $owner = $this->createStorage();
        $owner->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'owner'));

        $this->createStorage()->release(key: $key);

        $row = $this->fetchRow('not-mine');
        Assert::notNull($row);
        Assert::same($row['fingerprint'], 'owner');
    }

    public function releaseKeepsARecordWhoseTtlCoincidesWithTheClaimDeadline(): void
    {
        // claimTtlSeconds and the middleware's record TTL are both 3600 by
        // default, so a claim taken and a response stored within the same second
        // carry the SAME expires_at. The ownership token alone cannot tell those
        // two rows apart — only the `claimed` flag can, and without it a release
        // would delete a response already handed to the client.
        $key = new IdempotencyKey(value: 'coincide');
        $storage = $this->createStorage(claimTtlSeconds: 3600);
        $storage->claim(key: $key, fingerprint: new IdempotencyFingerprint(hash: 'mine'));

        // what a taking-over process would leave behind: a finished record whose
        // TTL deadline happens to equal our claim deadline
        $this->deleteRow('coincide');
        $this->insertRow(
            key: 'coincide',
            fingerprint: 'competitor',
            expiresAt: '2026-06-11 13:00:00',
            claimed: false,
            body: 'delivered',
        );

        $storage->release(key: $key);

        $row = $this->fetchRow('coincide');
        Assert::notNull($row);
        Assert::same($row['body'], 'delivered');
    }

    public function releaseKeepsAStoredRecord(): void
    {
        // a late release from a process whose claim was taken over must not
        // delete the response the taking-over process has already stored
        $storage = $this->createStorage();
        $key = new IdempotencyKey(value: 'stored-then-released');
        $fingerprint = new IdempotencyFingerprint(hash: 'h1');

        $storage->claim(key: $key, fingerprint: $fingerprint);
        $storage->store(record: IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: new IdempotencyResponse(statusCode: 200, headers: [], body: 'kept'),
            expiresAt: $this->now->modify('+3600 seconds'),
        ));

        $storage->release(key: $key);

        $loaded = $storage->load(key: $key);
        Assert::notNull($loaded);
        Assert::same($loaded->response->body, 'kept');
    }

    public function reapingAStaleClaimSparesTheClaimThatReplacedIt(): void
    {
        // the competitor wins the race between load()'s SELECT and its DELETE:
        // an unconditional "DELETE WHERE key = :k" would wipe its fresh claim
        // and let a second handler run
        $this->insertRow(key: 'takeover', fingerprint: 'stale', expiresAt: '2026-06-11 12:01:00', claimed: true);
        $this->insertRow(key: 'other-stale', fingerprint: 'other', expiresAt: '2026-06-11 12:01:00', claimed: true);

        $storage = $this->storageWithCompetitor(function (): void {
            $this->deleteRow('takeover');
            $this->insertRow(
                key: 'takeover',
                fingerprint: 'competitor',
                expiresAt: '2026-06-11 12:10:00',
                claimed: true,
            );
        });

        Assert::null($storage->load(key: new IdempotencyKey(value: 'takeover')));

        $row = $this->fetchRow('takeover');
        Assert::notNull($row);
        Assert::same($row['fingerprint'], 'competitor');
        Assert::notNull($this->fetchRow('other-stale'));
    }

    public function reapingAStaleClaimSparesAFinishedRecord(): void
    {
        // the competitor finished while we were deciding: the row is no longer a
        // claim, so the reap of a claim must not touch it even though it is
        // expired by our reading of the clock
        $this->insertRow(key: 'finished', fingerprint: 'stale', expiresAt: '2026-06-11 12:01:00', claimed: true);

        $storage = $this->storageWithCompetitor(function (): void {
            $this->deleteRow('finished');
            $this->insertRow(
                key: 'finished',
                fingerprint: 'competitor',
                expiresAt: '2026-06-11 12:01:30',
                claimed: false,
                body: 'done',
            );
        });

        Assert::null($storage->load(key: new IdempotencyKey(value: 'finished')));

        $row = $this->fetchRow('finished');
        Assert::notNull($row);
        Assert::same($row['fingerprint'], 'competitor');
    }

    public function reapingAnExpiredRecordSparesTheClaimThatReplacedIt(): void
    {
        // same race on the other branch of load(): the expired response is gone
        // and a new claim already sits in its place
        $this->insertRow(
            key: 'retried',
            fingerprint: 'old',
            expiresAt: '2026-06-11 12:01:00',
            claimed: false,
            body: 'old-response',
        );

        $storage = $this->storageWithCompetitor(function (): void {
            $this->deleteRow('retried');
            $this->insertRow(
                key: 'retried',
                fingerprint: 'competitor',
                expiresAt: '2026-06-11 12:10:00',
                claimed: true,
            );
        });

        Assert::null($storage->load(key: new IdempotencyKey(value: 'retried')));

        $row = $this->fetchRow('retried');
        Assert::notNull($row);
        Assert::same($row['fingerprint'], 'competitor');
        Assert::same((int) $row['claimed'], 1);
    }

    #[DataProvider('binaryBodyProvider')]
    public function storeAndLoadRoundTripABinaryBody(string $body): void
    {
        // a PDF or a ZIP is not valid UTF-8: PostgreSQL rejects it in a text
        // column, and the request whose side effects are already committed would
        // fail on the way out
        $storage = $this->createStorage();
        $key = new IdempotencyKey(value: 'binary-body');
        $fingerprint = new IdempotencyFingerprint(hash: 'h1');

        $storage->claim(key: $key, fingerprint: $fingerprint);
        $storage->store(record: IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: new IdempotencyResponse(statusCode: 200, headers: [], body: $body),
            expiresAt: $this->now->modify('+3600 seconds'),
        ));

        $row = $this->fetchRow('binary-body');
        Assert::notNull($row);
        Assert::same($row['body_encoding'], 'base64');
        Assert::same($row['body'], base64_encode($body));

        $loaded = $storage->load(key: $key);
        Assert::notNull($loaded);
        Assert::same($loaded->response->body, $body);
    }

    public static function binaryBodyProvider(): iterable
    {
        // each case must fail exactly one of the two checks, so neither can be
        // dropped without a test noticing
        yield 'invalid utf-8, no NUL' => ["\xFF\xFEbinary"];
        yield 'valid utf-8 with a NUL' => ["ok\x00tail"];
        yield 'both' => ["%PDF-1.4\x00\xFF\xFEbinary\x00tail"];
    }

    #[DataProvider('plainBodyProvider')]
    public function aTextBodyIsStoredVerbatim(string $body): void
    {
        $storage = $this->createStorage();
        $key = new IdempotencyKey(value: 'text-body');
        $fingerprint = new IdempotencyFingerprint(hash: 'h1');

        $storage->claim(key: $key, fingerprint: $fingerprint);
        $storage->store(record: IdempotencyRecord::restore(
            key: $key,
            fingerprint: $fingerprint,
            response: new IdempotencyResponse(statusCode: 200, headers: [], body: $body),
            expiresAt: $this->now->modify('+3600 seconds'),
        ));

        $row = $this->fetchRow('text-body');
        Assert::notNull($row);
        Assert::same($row['body_encoding'], 'plain');
        Assert::same($row['body'], $body);

        $loaded = $storage->load(key: $key);
        Assert::notNull($loaded);
        Assert::same($loaded->response->body, $body);
    }

    public static function plainBodyProvider(): iterable
    {
        yield 'ascii' => ['{"status":"ok"}'];
        yield 'multibyte utf-8' => ['{"город":"Москва","emoji":"🚀"}'];
        yield 'empty' => [''];
    }

    public function claimRowIsWrittenAsPlain(): void
    {
        $this->createStorage()->claim(
            key: new IdempotencyKey(value: 'claim-encoding'),
            fingerprint: new IdempotencyFingerprint(hash: 'h1'),
        );

        $row = $this->fetchRow('claim-encoding');
        Assert::notNull($row);
        Assert::same($row['body_encoding'], 'plain');
    }

    public function claimSweepsExpiredRowsWhenTheGcFires(): void
    {
        // nothing else collects them: an idempotency key is single-use, so the
        // lazy cleanup in load() practically never runs for a given key
        $this->insertRow(key: 'garbage', fingerprint: 'h0', expiresAt: '2026-06-11 11:00:00', claimed: false);

        $storage = $this->createStorage(gcDivisor: 1);

        Assert::true($storage->claim(
            key: new IdempotencyKey(value: 'sweeper'),
            fingerprint: new IdempotencyFingerprint(hash: 'h1'),
        ));

        Assert::null($this->fetchRow('garbage'));
        Assert::notNull($this->fetchRow('sweeper'));
    }

    public function claimSweepsNothingWhenTheDrawMisses(): void
    {
        // the sweep is probabilistic: with a divisor this large the draw
        // realistically never hits, so an unconditional sweep shows up here
        $this->insertRow(key: 'garbage', fingerprint: 'h0', expiresAt: '2026-06-11 11:00:00', claimed: false);

        $storage = $this->createStorage(gcDivisor: PHP_INT_MAX);

        $storage->claim(
            key: new IdempotencyKey(value: 'sweeper'),
            fingerprint: new IdempotencyFingerprint(hash: 'h1'),
        );

        Assert::notNull($this->fetchRow('garbage'));
    }

    public function claimSweepsNothingWhenTheGcIsDisabled(): void
    {
        $this->insertRow(key: 'garbage', fingerprint: 'h0', expiresAt: '2026-06-11 11:00:00', claimed: false);

        $storage = $this->createStorage(gcDivisor: 0);

        $storage->claim(
            key: new IdempotencyKey(value: 'sweeper'),
            fingerprint: new IdempotencyFingerprint(hash: 'h1'),
        );

        Assert::notNull($this->fetchRow('garbage'));
    }

    public function aRefusedClaimSweepsNothing(): void
    {
        // the sweep rides on a successful claim only: a losing request is
        // already on the slow path and must not pay for a table scan too
        $this->insertRow(key: 'garbage', fingerprint: 'h0', expiresAt: '2026-06-11 11:00:00', claimed: false);

        $storage = $this->createStorage(gcDivisor: 1);
        $key = new IdempotencyKey(value: 'contended');
        $fingerprint = new IdempotencyFingerprint(hash: 'h1');

        $this->insertRow(key: 'contended', fingerprint: 'h1', expiresAt: '2026-06-11 13:00:00', claimed: true);

        Assert::false($storage->claim(key: $key, fingerprint: $fingerprint));
        Assert::notNull($this->fetchRow('garbage'));
    }

    public function rejectsNegativeGcDivisor(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        new DbIdempotencyStorage(
            db: $this->db,
            clock: $this->clock,
            gcDivisor: -1,
        );
    }

    private function createStorage(int $claimTtlSeconds = 3600, int $gcDivisor = 0): DbIdempotencyStorage
    {
        return new DbIdempotencyStorage(
            db: $this->db,
            clock: $this->clock,
            claimTtlSeconds: $claimTtlSeconds,
            gcDivisor: $gcDivisor,
        );
    }

    /**
     * A storage whose clock lets a competitor change the row between the SELECT
     * `load()` makes and the conditional DELETE it issues afterwards.
     */
    private function storageWithCompetitor(\Closure $competitor): DbIdempotencyStorage
    {
        $now = new \DateTimeImmutable('2026-06-11 12:05:00');

        return new DbIdempotencyStorage(
            db: $this->db,
            clock: new ScriptedClock(static function (int $call) use ($now, $competitor): \DateTimeImmutable {
                if ($call === 2) {
                    $competitor();
                }

                return $now;
            }),
            claimTtlSeconds: 60,
            gcDivisor: 0,
        );
    }

    private function insertRow(
        string $key,
        string $fingerprint,
        string $expiresAt,
        bool $claimed,
        string $body = '',
    ): void {
        $this->db->createCommand(sql: '
            INSERT INTO idempotency_keys ("key", fingerprint, status_code, headers, body, body_encoding, expires_at, claimed)
            VALUES (:key, :fingerprint, 200, \'{}\', :body, \'plain\', :expires_at, :claimed)
        ')->bindValues([
            ':key' => $key,
            ':fingerprint' => $fingerprint,
            ':body' => $body,
            ':expires_at' => $expiresAt,
            ':claimed' => $claimed ? 1 : 0,
        ])->execute();
    }

    private function deleteRow(string $key): void
    {
        $this->db->createCommand(sql: 'DELETE FROM idempotency_keys WHERE "key" = :key')
            ->bindValues([':key' => $key])
            ->execute();
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
