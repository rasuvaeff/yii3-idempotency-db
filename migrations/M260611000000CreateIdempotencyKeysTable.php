<?php

declare(strict_types=1);

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates the idempotency-keys table used by {@see \Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage}.
 *
 * The table name defaults to `idempotency_keys` and must match the `table` argument
 * of {@see \Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage}. To use a custom name,
 * bind the constructor argument in your DI configuration:
 *
 * ```php
 * M260611000000CreateIdempotencyKeysTable::class => [
 *     '__construct()' => ['table' => 'my_idempotency_keys'],
 * ],
 * ```
 */
final class M260611000000CreateIdempotencyKeysTable implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    /**
     * @param non-empty-string $table
     */
    public function __construct(
        private readonly string $table = 'idempotency_keys',
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            $this->table,
            [
                'key' => 'string(255) NOT NULL PRIMARY KEY',
                'fingerprint' => 'string(64) NOT NULL',
                'status_code' => 'smallint NOT NULL DEFAULT 0',
                'headers' => "text NOT NULL DEFAULT '{}'",
                'body' => "text NOT NULL DEFAULT ''",
                'expires_at' => 'string(30) NOT NULL',
                'claimed' => 'boolean NOT NULL DEFAULT FALSE',
            ],
        );

        $b->createIndex($this->table, 'idx_idempotency_expires_at', 'expires_at');
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table);
    }
}
