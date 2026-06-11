<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Yiisoft\Db\Cache\SchemaCache;
use Yiisoft\Db\Sqlite\Connection as SqliteConnection;
use Yiisoft\Db\Sqlite\Driver as SqliteDriver;
use Yiisoft\Test\Support\SimpleCache\MemorySimpleCache;

$now = new DateTimeImmutable('2026-06-11 12:00:00');
$clock = new class($now) implements ClockInterface {
    public function __construct(private DateTimeImmutable $now) {}
    public function now(): DateTimeImmutable { return $this->now; }
};

$driver = new SqliteDriver(dsn: 'sqlite::memory:');
$schemaCache = new SchemaCache(psrCache: new MemorySimpleCache());
$db = new SqliteConnection(driver: $driver, schemaCache: $schemaCache);
$db->open();

$db->createCommand(sql: '
    CREATE TABLE idempotency_keys (
        "key"        VARCHAR(190) PRIMARY KEY,
        fingerprint  VARCHAR(64)  NOT NULL,
        status_code  INTEGER      NOT NULL DEFAULT 0,
        headers      TEXT         NOT NULL DEFAULT \'{}\',
        body         TEXT         NOT NULL DEFAULT \'\',
        expires_at   VARCHAR(30)  NOT NULL,
        claimed      INTEGER      NOT NULL DEFAULT 0
    )
')->execute();

$storage = new DbIdempotencyStorage(db: $db, clock: $clock);

$key = new IdempotencyKey(value: 'order-123');
$fingerprint = new IdempotencyFingerprint(hash: hash('sha256', "POST\n/orders\n{}"));

echo "1. Claim key: ";
$claimed = $storage->claim(key: $key, fingerprint: $fingerprint);
echo $claimed ? 'OK' : 'FAIL';
echo "\n";

$record = new IdempotencyRecord(
    key: $key,
    fingerprint: $fingerprint,
    response: new IdempotencyResponse(
        statusCode: 201,
        headers: ['Content-Type' => ['application/json']],
        body: '{"order_id":456}',
    ),
    expiresAt: $now->modify('+3600 seconds'),
);

echo "2. Store response: ";
$storage->store(record: $record);
echo "OK\n";

echo "3. Load and replay: ";
$loaded = $storage->load(key: $key);
if ($loaded !== null) {
    echo "statusCode={$loaded->response->statusCode}, body={$loaded->response->body}\n";
} else {
    echo "FAIL (null)\n";
}

echo "4. Duplicate claim: ";
$again = $storage->claim(key: $key, fingerprint: $fingerprint);
echo $again ? 'FAIL (should be false)' : 'OK (rejected as expected)';
echo "\n";

$db->close();
