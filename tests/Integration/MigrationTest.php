<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests\Integration;

use M260611000000CreateIdempotencyKeysTable;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Lifecycle\AfterTest;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[Test]
#[CoversNothing]
final class MigrationTest
{
    private ConnectionInterface $db;

    private MigrationBuilder $builder;

    #[BeforeTest]
    public function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/M260611000000CreateIdempotencyKeysTable.php';

        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->builder = new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer());
    }

    #[AfterTest]
    public function tearDown(): void
    {
        $this->db->close();
    }

    public function createsAndDropsIdempotencyKeysTable(): void
    {
        $migration = new M260611000000CreateIdempotencyKeysTable();

        $migration->up($this->builder);

        $schema = $this->db->getTableSchema('idempotency_keys', true);
        Assert::notNull($schema);
        Assert::notNull($schema->getColumn('key'));
        Assert::notNull($schema->getColumn('fingerprint'));
        Assert::notNull($schema->getColumn('status_code'));
        Assert::notNull($schema->getColumn('headers'));
        Assert::notNull($schema->getColumn('body'));
        Assert::notNull($schema->getColumn('expires_at'));
        Assert::notNull($schema->getColumn('claimed'));
        Assert::same($schema->getPrimaryKey(), ['key']);

        $migration->down($this->builder);

        Assert::null($this->db->getTableSchema('idempotency_keys', true));
    }

    public function createsTableWithCustomName(): void
    {
        (new M260611000000CreateIdempotencyKeysTable(table: 'custom_keys'))->up($this->builder);

        Assert::notNull($this->db->getTableSchema('custom_keys', true));
        Assert::null($this->db->getTableSchema('idempotency_keys', true));
    }

    public function migratedTableIsUsableByStorage(): void
    {
        (new M260611000000CreateIdempotencyKeysTable())->up($this->builder);

        $now = new \DateTimeImmutable('2026-06-11 12:00:00');
        $clock = new class ($now) implements ClockInterface {
            public function __construct(
                private \DateTimeImmutable $now,
            ) {}

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };

        $storage = new DbIdempotencyStorage(db: $this->db, clock: $clock);

        $key = new IdempotencyKey(value: 'test-key');
        $fingerprint = new IdempotencyFingerprint(hash: 'test-hash');

        $claimed = $storage->claim(key: $key, fingerprint: $fingerprint);

        Assert::true($claimed);
    }
}
