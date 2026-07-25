<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Migration;

use Rasuvaeff\Yii3IdempotencyDb\IdempotencyKeysTableName;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Creates the idempotency-keys table used by {@see \Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage}.
 *
 * The table name comes from {@see IdempotencyKeysTableName}, which `config/di.php`
 * builds from params — one source of truth for the migration and the
 * runtime code alike. Register the migration by namespace:
 *
 * ```php
 * MigrationService::class => [
 *     'setSourceNamespaces()' => [['Rasuvaeff\\Yii3IdempotencyDb\\Migration']],
 * ],
 * ```
 *
 * @api
 */
final class M260611000000CreateIdempotencyKeysTable implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    public function __construct(
        private readonly IdempotencyKeysTableName $table = new IdempotencyKeysTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        $b->createTable(
            $this->table->value,
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

        // index names follow the table name: in PostgreSQL they are unique per
        // schema, so two installations sharing one schema would collide on a
        // hard-coded name
        $b->createIndex(
            $this->table->value,
            sprintf('idx_%s_expires_at', $this->table->forIndexName()),
            'expires_at',
        );
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropTable($this->table->value);
    }
}
