<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests;

use Rasuvaeff\Yii3IdempotencyDb\IdempotencyKeysTableName;
use Rasuvaeff\Yii3IdempotencyDb\Migration\M260611000000CreateIdempotencyKeysTable;
use Rasuvaeff\Yii3IdempotencyDb\Migration\M260822000000AddBodyEncodingColumn;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Exception\NotSupportedException;
use Yiisoft\Db\Migration\Informer\NullMigrationInformer;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Injector\Injector;
use Yiisoft\Test\Support\Container\SimpleContainer;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

/**
 * The migration is created by `yiisoft/db-migration` through `Injector::make()`,
 * not by the container, so a test that instantiates it directly proves nothing
 * about whether configuration actually reaches it. These go through the real
 * resolver.
 */
#[Test]
#[Covers(M260611000000CreateIdempotencyKeysTable::class)]
#[Covers(M260822000000AddBodyEncodingColumn::class)]
final class MigrationTableNameTest
{
    private ConnectionInterface $db;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->db = new SqliteConnection(
            driver: new SqliteDriver(dsn: 'sqlite::memory:'),
            schemaCache: new SchemaCache(psrCache: new MemorySimpleCache()),
        );
    }

    public function containerBoundTableNameReachesTheMigration(): void
    {
        $migration = $this->make(new SimpleContainer([
            IdempotencyKeysTableName::class => new IdempotencyKeysTableName('custom_tbl'),
        ]));

        $migration->up($this->builder());

        Assert::notNull($this->db->getTableSchema('custom_tbl', true));
        Assert::null($this->db->getTableSchema('idempotency_keys', true));
    }

    public function withoutABindingTheDefaultNameIsUsed(): void
    {
        // Injector falls back to the parameter default, so the package stays
        // usable with no configuration at all
        $migration = $this->make(new SimpleContainer([]));

        $migration->up($this->builder());

        Assert::notNull($this->db->getTableSchema('idempotency_keys', true));
    }

    public function createsTheDocumentedColumnSet(): void
    {
        // the column list IS the contract with the runtime code: a column
        // silently dropped here surfaces only as a failing query in production
        $migration = $this->make(new SimpleContainer([]));

        $migration->up($this->builder());

        $schema = $this->db->getTableSchema('idempotency_keys', true);
        Assert::notNull($schema);
        Assert::same(array_keys($schema->getColumns()), [
            'key',
            'fingerprint',
            'status_code',
            'headers',
            'body',
            'expires_at',
            'claimed',
        ]);
    }

    public function textColumnsCarryNoLiteralDefault(): void
    {
        // MySQL rejects a literal DEFAULT on a TEXT/BLOB column (error 1101), so
        // `migrate:up` used to abort there before creating anything
        $this->make(new SimpleContainer([]))->up($this->builder());

        $schema = $this->db->getTableSchema('idempotency_keys', true);
        Assert::notNull($schema);

        $headers = $schema->getColumn('headers');
        Assert::notNull($headers);
        Assert::null($headers->getDefaultValue());

        $body = $schema->getColumn('body');
        Assert::notNull($body);
        Assert::null($body->getDefaultValue());
    }

    public function bodyEncodingColumnIsAddedWithThePlainDefault(): void
    {
        // rows written before the column existed hold a verbatim body, which is
        // exactly what the default says
        $builder = $this->builder();
        $this->make(new SimpleContainer([]))->up($builder);
        $this->makeBodyEncoding(new SimpleContainer([]))->up($builder);

        $schema = $this->db->getTableSchema('idempotency_keys', true);
        Assert::notNull($schema);
        Assert::same(array_keys($schema->getColumns()), [
            'key',
            'fingerprint',
            'status_code',
            'headers',
            'body',
            'expires_at',
            'claimed',
            'body_encoding',
        ]);

        $column = $schema->getColumn('body_encoding');
        Assert::notNull($column);
        Assert::same($column->getDefaultValue(), 'plain');
    }

    public function bodyEncodingMigrationFollowsTheConfiguredTableName(): void
    {
        $container = new SimpleContainer([
            IdempotencyKeysTableName::class => new IdempotencyKeysTableName('custom_tbl'),
        ]);
        $builder = $this->builder();

        $this->make($container)->up($builder);
        $this->makeBodyEncoding($container)->up($builder);

        $schema = $this->db->getTableSchema('custom_tbl', true);
        Assert::notNull($schema);
        Assert::notNull($schema->getColumn('body_encoding'));
    }

    public function bodyEncodingMigrationDropsTheColumnOnDown(): void
    {
        // `yiisoft/db-sqlite` refuses DROP COLUMN outright, so the refusal is
        // the only observable proof here that `down()` asks for the column and
        // nothing coarser — a `down()` that dropped the whole table (which
        // SQLite does support) would sail through this test without raising.
        $builder = $this->builder();
        $this->make(new SimpleContainer([]))->up($builder);
        $migration = $this->makeBodyEncoding(new SimpleContainer([]));
        $migration->up($builder);

        Expect::exception(NotSupportedException::class);

        $migration->down($builder);
    }

    public function indexNamesFollowTheTableName(): void
    {
        // hard-coded index names collide in PostgreSQL, where names are unique
        // per schema rather than per table
        $migration = $this->make(new SimpleContainer([
            IdempotencyKeysTableName::class => new IdempotencyKeysTableName('custom_tbl'),
        ]));

        $migration->up($this->builder());

        $indexes = $this->indexNames('custom_tbl');
        Assert::true(in_array('idx_custom_tbl_expires_at', $indexes, strict: true));
    }

    public function indexesCoverTheDocumentedColumns(): void
    {
        $migration = $this->make(new SimpleContainer([]));

        $migration->up($this->builder());

        Assert::same($this->indexColumns('idx_idempotency_keys_expires_at'), ['expires_at']);
    }

    public function downDropsTheConfiguredTable(): void
    {
        $migration = $this->make(new SimpleContainer([
            IdempotencyKeysTableName::class => new IdempotencyKeysTableName('custom_tbl'),
        ]));
        $builder = $this->builder();

        $migration->up($builder);
        $migration->down($builder);

        Assert::null($this->db->getTableSchema('custom_tbl', true));
    }

    private function make(SimpleContainer $container): M260611000000CreateIdempotencyKeysTable
    {
        /** @var M260611000000CreateIdempotencyKeysTable */
        return (new Injector($container))->make(M260611000000CreateIdempotencyKeysTable::class);
    }

    private function makeBodyEncoding(SimpleContainer $container): M260822000000AddBodyEncodingColumn
    {
        /** @var M260822000000AddBodyEncodingColumn */
        return (new Injector($container))->make(M260822000000AddBodyEncodingColumn::class);
    }

    private function builder(): MigrationBuilder
    {
        return new MigrationBuilder($this->db, new NullMigrationInformer());
    }

    /**
     * @return list<string>
     */
    private function indexColumns(string $index): array
    {
        $columns = [];

        /** @var array<array-key, mixed> $row */
        foreach ($this->db->createCommand(sprintf('PRAGMA index_info(%s)', $index))->queryAll() as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $columns[] = $row['name'];
            }
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    private function indexNames(string $table): array
    {
        $names = [];

        /** @var array<array-key, mixed> $row */
        foreach ($this->db->createCommand(sprintf('PRAGMA index_list(%s)', $table))->queryAll() as $row) {
            if (is_array($row) && is_string($row['name'] ?? null)) {
                $names[] = $row['name'];
            }
        }

        return $names;
    }
}
