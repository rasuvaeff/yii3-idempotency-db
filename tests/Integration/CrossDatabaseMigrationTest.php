<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests\Integration;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Rasuvaeff\Yii3IdempotencyDb\Migration\M260611000000CreateIdempotencyKeysTable;
use Rasuvaeff\Yii3IdempotencyDb\Migration\M260822000000AddBodyEncodingColumn;
use Testo\Assert;
use Testo\Codecov\CoversNothing;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Mysql\Connection as MysqlConnection;
use Yiisoft\Db\Mysql\Driver as MysqlDriver;
use Yiisoft\Db\Pgsql\Connection as PgsqlConnection;
use Yiisoft\Db\Pgsql\Driver as PgsqlDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The bundled migrations are otherwise only ever applied to SQLite, which
 * accepts DDL the other engines reject — a literal `DEFAULT` on a TEXT column
 * aborts `migrate:up` on MySQL with error 1101 and creates nothing. Runs only
 * when `IDEMPOTENCY_TEST_DB` names a live server; the ungated
 * `database-integration` matrix job in CI supplies both.
 */
#[Test]
#[CoversNothing]
final class CrossDatabaseMigrationTest
{
    public function migrationsApplyAndRevertOnConfiguredDatabase(): void
    {
        $database = getenv('IDEMPOTENCY_TEST_DB');

        if ($database !== 'mysql' && $database !== 'pgsql') {
            Assert::true($database === false || $database === '');

            return;
        }

        $db = $this->connection($database);
        $db->open();

        try {
            $db->createCommand('DROP TABLE IF EXISTS idempotency_keys')->execute();

            $create = new M260611000000CreateIdempotencyKeysTable();
            $addEncoding = new M260822000000AddBodyEncodingColumn();
            $builder = new MigrationBuilder(db: $db, informer: new NullMigrationInformer());

            $create->up($builder);
            $addEncoding->up($builder);

            // the runtime contract against the migrated table on a real
            // engine: claim writes the column the second migration added, so
            // this fails the moment the two migrations stop composing
            $now = new \DateTimeImmutable('2026-10-27 12:00:00');
            $clock = new readonly class ($now) implements ClockInterface {
                public function __construct(
                    private \DateTimeImmutable $now,
                ) {}

                #[\Override]
                public function now(): \DateTimeImmutable
                {
                    return $this->now;
                }
            };
            $storage = new DbIdempotencyStorage(db: $db, clock: $clock, gcDivisor: 0);

            $key = new IdempotencyKey(value: 'cross-db-key');
            $fingerprint = new IdempotencyFingerprint(hash: 'cross-db-hash');

            Assert::true($storage->claim(key: $key, fingerprint: $fingerprint));

            $storage->store(record: IdempotencyRecord::restore(
                key: $key,
                fingerprint: $fingerprint,
                response: new IdempotencyResponse(
                    statusCode: 303,
                    headers: ['Location' => ['/guestbook?page=3']],
                    body: '{"ok":true}',
                ),
                expiresAt: $now->modify('+3600 seconds'),
            ));

            $loaded = $storage->load(key: $key);

            Assert::notNull($loaded);
            Assert::same($loaded->response->statusCode, 303);
            Assert::same($loaded->response->headers, ['Location' => ['/guestbook?page=3']]);
            Assert::same($loaded->response->body, '{"ok":true}');

            $addEncoding->down($builder);
            $create->down($builder);

            Assert::null($db->getTableSchema('idempotency_keys', true));
        } finally {
            $db->createCommand('DROP TABLE IF EXISTS idempotency_keys')->execute();
            $db->close();
        }
    }

    private function connection(string $database): ConnectionInterface
    {
        $cache = new SchemaCache(psrCache: new MemorySimpleCache());
        $mysqlPort = getenv('IDEMPOTENCY_TEST_MYSQL_PORT') ?: '3306';
        $pgsqlPort = getenv('IDEMPOTENCY_TEST_PGSQL_PORT') ?: '5432';

        return $database === 'mysql'
            ? new MysqlConnection(
                driver: new MysqlDriver(
                    dsn: sprintf('mysql:host=127.0.0.1;port=%s;dbname=idempotency;charset=utf8mb4', $mysqlPort),
                    username: 'root',
                    password: 'idempotency',
                ),
                schemaCache: $cache,
            )
            : new PgsqlConnection(
                driver: new PgsqlDriver(
                    dsn: sprintf('pgsql:host=127.0.0.1;port=%s;dbname=idempotency', $pgsqlPort),
                    username: 'postgres',
                    password: 'idempotency',
                ),
                schemaCache: $cache,
            );
    }
}
