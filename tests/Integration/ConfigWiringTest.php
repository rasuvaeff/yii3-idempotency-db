<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests\Integration;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
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
#[Test]
#[CoversNothing]
final class ConfigWiringTest
{
    public function bindsOnlyTheStorageKey(): void
    {
        Assert::same(array_keys($this->loadDb([])), [IdempotencyStorage::class]);
    }

    public function storageFactoryBuildsDbStorage(): void
    {
        $storage = $this->resolveStorage([
            'rasuvaeff/yii3-idempotency-db' => ['table' => 'custom_keys', 'claimTtlSeconds' => 60],
        ]);

        Assert::instanceOf($storage, DbIdempotencyStorage::class);
    }

    public function storageFactoryUsesDefaultsWhenParamsAbsent(): void
    {
        Assert::instanceOf($this->resolveStorage([]), DbIdempotencyStorage::class);
    }

    public function coreAndBackendDoNotShareDiKeys(): void
    {
        $overlap = array_intersect_key($this->loadCore(), $this->loadDb([]));

        Assert::same($overlap, [], 'core and -db must not define the same di key (yiisoft/config Duplicate key)');
    }

    private function resolveStorage(array $params): IdempotencyStorage
    {
        $definitions = $this->loadDb($params);
        $factory = $definitions[IdempotencyStorage::class];
        Assert::true(is_callable($factory));

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

        $storage = $factory($this->sqlite(), $clock);
        Assert::instanceOf($storage, IdempotencyStorage::class);

        return $storage;
    }

    private function loadDb(array $params): array
    {
        return require dirname(__DIR__, 2) . '/config/di.php';
    }

    private function loadCore(): array
    {
        return require dirname(__DIR__, 2) . '/vendor/rasuvaeff/yii3-idempotency/config/di.php';
    }

    private function sqlite(): ConnectionInterface
    {
        $driver = new SqliteDriver(dsn: 'sqlite::memory:');

        return new SqliteConnection(driver: $driver, schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()));
    }
}
