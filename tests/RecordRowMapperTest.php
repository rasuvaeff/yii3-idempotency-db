<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests;

use Rasuvaeff\Yii3IdempotencyDb\Exception\InvalidRecordRowException;
use Rasuvaeff\Yii3IdempotencyDb\RecordRowMapper;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(RecordRowMapper::class)]
final class RecordRowMapperTest
{
    private RecordRowMapper $mapper;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->mapper = new RecordRowMapper();
    }

    public function mapsRowWithNativeTypes(): void
    {
        $record = $this->mapper->map($this->row());

        Assert::same($record->key->value, 'order-123');
        Assert::same($record->fingerprint->hash, 'abc123hash');
        Assert::same($record->response->statusCode, 200);
        Assert::same($record->response->body, '{"status":"ok"}');
        Assert::same($record->response->headers, ['Content-Type' => ['application/json']]);
        Assert::same($record->expiresAt->format('Y-m-d H:i:s'), '2026-06-12 12:00:00');
    }

    public function mapsRowWithJsonHeaders(): void
    {
        $row = $this->row(headers: '{"X-Custom":["val1","val2"]}');

        $record = $this->mapper->map($row);

        Assert::same($record->response->headers, ['X-Custom' => ['val1', 'val2']]);
    }

    public function mapsRowWithEmptyStringHeaders(): void
    {
        $row = $this->row(headers: '');

        $record = $this->mapper->map($row);

        Assert::same($record->response->headers, []);
    }

    public function mapsRowWithEmptyJsonObjectHeaders(): void
    {
        $row = $this->row(headers: '{}');

        $record = $this->mapper->map($row);

        Assert::same($record->response->headers, []);
    }

    public function mapsRowWithNativeArrayHeaders(): void
    {
        $row = $this->row(headers: ['Content-Type' => ['text/html']]);

        $record = $this->mapper->map($row);

        Assert::same($record->response->headers, ['Content-Type' => ['text/html']]);
    }

    public function mapsRowWithStatusCodeAsString(): void
    {
        $row = $this->row(statusCode: '201');

        $record = $this->mapper->map($row);

        Assert::same($record->response->statusCode, 201);
    }

    public static function invalidRowProvider(): iterable
    {
        $base = [
            'key' => 'order-123',
            'fingerprint' => 'abc123hash',
            'status_code' => 200,
            'headers' => '{}',
            'body' => 'ok',
            'expires_at' => '2026-06-12 12:00:00',
        ];

        yield 'missing key' => [self::without($base, 'key'), 'key'];
        yield 'non-string key' => [['key' => 123] + $base, 'key'];
        yield 'missing fingerprint' => [self::without($base, 'fingerprint'), 'fingerprint'];
        yield 'non-string fingerprint' => [['fingerprint' => 123] + $base, 'fingerprint'];
        yield 'missing status_code' => [self::without($base, 'status_code'), 'status_code'];
        yield 'non-numeric status_code' => [['status_code' => 'abc'] + $base, 'status_code'];
        yield 'status_code with trailing junk' => [['status_code' => '12x'] + $base, 'status_code'];
        yield 'status_code with leading junk' => [['status_code' => 'x12'] + $base, 'status_code'];
        yield 'headers native non-string name' => [['headers' => [0 => ['v']]] + $base, 'header name'];
        yield 'headers value not array' => [['headers' => ['X' => 'str']] + $base, 'values'];
        yield 'missing headers' => [self::without($base, 'headers'), 'headers'];
        yield 'invalid headers type' => [['headers' => 123] + $base, 'headers'];
        yield 'malformed headers json' => [['headers' => 'not-json'] + $base, 'headers'];
        yield 'headers json not array' => [['headers' => '"string"'] + $base, 'headers'];
        yield 'headers native non-string value' => [['headers' => ['X' => [123]]] + $base, 'header "X" value'];
        yield 'missing body' => [self::without($base, 'body'), 'body'];
        yield 'non-string body' => [['body' => 123] + $base, 'body'];
        yield 'missing expires_at' => [self::without($base, 'expires_at'), 'expires_at'];
        yield 'invalid expires_at' => [['expires_at' => 'not-a-date'] + $base, 'expires_at'];
    }

    #[DataProvider('invalidRowProvider')]
    public function throwsOnInvalidRow(array $row, string $needle): void
    {
        try {
            $this->mapper->map($row);
            Assert::fail('Expected InvalidRecordRowException');
        } catch (InvalidRecordRowException $e) {
            Assert::true(preg_match('/' . preg_quote($needle, '/') . '/', $e->getMessage()) === 1);
        }
    }

    public function parsesExpiresAtAsUtc(): void
    {
        $record = $this->mapper->map($this->row(expiresAt: '2026-06-12 12:00:00'));

        Assert::same($record->expiresAt->getTimezone()->getName(), 'UTC');
    }

    public function extractsExpiresAtFromRow(): void
    {
        $expiresAt = $this->mapper->expiresAt($this->row(expiresAt: '2026-06-12 12:00:00'));

        Assert::same($expiresAt->format('Y-m-d H:i:s'), '2026-06-12 12:00:00');
    }

    public function expiresAtThrowsOnMissingColumn(): void
    {
        try {
            $this->mapper->expiresAt(['key' => 'order-123']);
            Assert::fail('Expected InvalidRecordRowException');
        } catch (InvalidRecordRowException $e) {
            Assert::true(preg_match('/expires_at/', $e->getMessage()) === 1);
        }
    }

    public function throwsOnInvalidKeyValue(): void
    {
        $row = $this->row(key: '');

        try {
            $this->mapper->map($row);
            Assert::fail('Expected InvalidRecordRowException');
        } catch (InvalidRecordRowException $e) {
            Assert::string($e->getMessage())->contains('Invalid key in DB row');
        }
    }

    private function row(
        string $key = 'order-123',
        string $fingerprint = 'abc123hash',
        int|string $statusCode = 200,
        array|string $headers = '{"Content-Type":["application/json"]}',
        string $body = '{"status":"ok"}',
        string $expiresAt = '2026-06-12 12:00:00',
    ): array {
        return [
            'key' => $key,
            'fingerprint' => $fingerprint,
            'status_code' => $statusCode,
            'headers' => $headers,
            'body' => $body,
            'expires_at' => $expiresAt,
        ];
    }

    private static function without(array $row, string $column): array
    {
        unset($row[$column]);

        return $row;
    }
}
