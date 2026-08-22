<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Migration;

use Rasuvaeff\Yii3IdempotencyDb\IdempotencyKeysTableName;
use Rasuvaeff\Yii3IdempotencyDb\RecordRowMapper;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;
use Yiisoft\Db\Migration\TransactionalMigrationInterface;

/**
 * Adds the `body_encoding` column read by {@see \Rasuvaeff\Yii3IdempotencyDb\RecordRowMapper}.
 *
 * A `text` column cannot hold a response body that is not valid UTF-8 — a PDF, a
 * ZIP, anything with a NUL byte — so such a body is base64-encoded on write and
 * this column records that it was. Rows written before this migration carry the
 * default, which says the body is stored verbatim.
 *
 * **This migration is required**: {@see \Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage::claim()}
 * writes the column on every claim and fails against a table without it.
 *
 * Reverting needs a driver that supports `DROP COLUMN`; `yiisoft/db-sqlite`
 * does not implement it and raises `NotSupportedException` on `down()`.
 *
 * @api
 */
final readonly class M260822000000AddBodyEncodingColumn implements RevertibleMigrationInterface, TransactionalMigrationInterface
{
    private const string COLUMN = 'body_encoding';

    public function __construct(
        private IdempotencyKeysTableName $table = new IdempotencyKeysTableName(),
    ) {}

    #[\Override]
    public function up(MigrationBuilder $b): void
    {
        // a literal DEFAULT is safe here — the restriction MySQL enforces applies
        // to TEXT/BLOB columns, not to VARCHAR
        $b->addColumn(
            $this->table->value,
            self::COLUMN,
            sprintf("string(16) NOT NULL DEFAULT '%s'", RecordRowMapper::ENCODING_PLAIN),
        );
    }

    #[\Override]
    public function down(MigrationBuilder $b): void
    {
        $b->dropColumn($this->table->value, self::COLUMN);
    }
}
