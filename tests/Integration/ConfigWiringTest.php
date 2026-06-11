<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * Exercises the package `config/di.php`, which is covered by neither cs, psalm,
 * nor the unit suite. The backend must bind exactly the swappable
 * `IdempotencyStorage` key and nothing the core package already binds —
 * yiisoft/config rejects duplicate keys across vendor packages.
 */
#[CoversNothing]
final class ConfigWiringTest extends TestCase
{
    #[Test]
    public function bindsOnlyTheStorageKey(): void
    {
        $this->assertSame([IdempotencyStorage::class], array_keys($this->loadDb([])));
    }

    #[Test]
    public function storageFactoryBuildsDbStorage(): void
    {
        $storage = $this->resolveStorage([
            'rasuvaeff/yii3-idempotency-db' => ['table' => 'custom_keys', 'claimTtlSeconds' => 60],
        ]);

        $this->assertInstanceOf(DbIdempotencyStorage::class, $storage);
    }

    #[Test]
    public function storageFactoryUsesDefaultsWhenParamsAbsent(): void
    {
        $this->assertInstanceOf(DbIdempotencyStorage::class, $this->resolveStorage([]));
    }

    #[Test]
    public function coreAndBackendDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadDb([]));

        $this->assertSame(
            [],
            $overlap,
            'core and -db must not define the same di key (yiisoft/config Duplicate key)',
        );
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveStorage(array $params): IdempotencyStorage
    {
        $definitions = $this->loadDb($params);
        $factory = $definitions[IdempotencyStorage::class];
        $this->assertIsCallable($factory);

        $clock = $this->createStub(ClockInterface::class);
        $clock->method('now')->willReturn(new \DateTimeImmutable('2026-06-11 12:00:00'));

        $storage = $factory($this->sqlite(), $clock);
        $this->assertInstanceOf(IdempotencyStorage::class, $storage);

        return $storage;
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function loadDb(array $params): array
    {
        return require dirname(__DIR__, 2) . '/config/di.php';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadCore(): array
    {
        $params = [];

        return require dirname(__DIR__, 2) . '/vendor/rasuvaeff/yii3-idempotency/config/di.php';
    }

    private function sqlite(): ConnectionInterface
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');

        return new SqliteConnection(driver: $driver, schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()));
    }
}
