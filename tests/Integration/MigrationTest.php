<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests\Integration;

use M260611000000CreateIdempotencyKeysTable;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

#[CoversNothing]
final class MigrationTest extends TestCase
{
    private ConnectionInterface $db;

    private MigrationBuilder $builder;

    #[\Override]
    protected function setUp(): void
    {
        require_once dirname(__DIR__, 2) . '/migrations/M260611000000CreateIdempotencyKeysTable.php';

        $driver = new SqliteDriver(dsn: 'sqlite::memory:');
        $schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
        $this->db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
        $this->db->open();

        $this->builder = new MigrationBuilder(db: $this->db, informer: new NullMigrationInformer());
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->close();
    }

    #[Test]
    public function createsAndDropsIdempotencyKeysTable(): void
    {
        $migration = new M260611000000CreateIdempotencyKeysTable();

        $migration->up($this->builder);

        $schema = $this->db->getTableSchema('idempotency_keys', true);
        $this->assertNotNull($schema);
        $this->assertNotNull($schema->getColumn('key'));
        $this->assertNotNull($schema->getColumn('fingerprint'));
        $this->assertNotNull($schema->getColumn('status_code'));
        $this->assertNotNull($schema->getColumn('headers'));
        $this->assertNotNull($schema->getColumn('body'));
        $this->assertNotNull($schema->getColumn('expires_at'));
        $this->assertNotNull($schema->getColumn('claimed'));
        $this->assertSame(['key'], $schema->getPrimaryKey());

        $migration->down($this->builder);

        $this->assertNull($this->db->getTableSchema('idempotency_keys', true));
    }

    #[Test]
    public function createsTableWithCustomName(): void
    {
        (new M260611000000CreateIdempotencyKeysTable(table: 'custom_keys'))->up($this->builder);

        $this->assertNotNull($this->db->getTableSchema('custom_keys', true));
        $this->assertNull($this->db->getTableSchema('idempotency_keys', true));
    }

    #[Test]
    public function migratedTableIsUsableByStorage(): void
    {
        (new M260611000000CreateIdempotencyKeysTable())->up($this->builder);

        $now = new \DateTimeImmutable('2026-06-11 12:00:00');
        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn($now);

        $storage = new DbIdempotencyStorage(db: $this->db, clock: $clock);

        $key = new IdempotencyKey(value: 'test-key');
        $fingerprint = new IdempotencyFingerprint(hash: 'test-hash');

        $claimed = $storage->claim(key: $key, fingerprint: $fingerprint);

        $this->assertTrue($claimed);
    }
}
