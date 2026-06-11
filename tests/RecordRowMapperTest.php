<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3IdempotencyDb\Exception\InvalidRecordRowException;
use Rasuvaeff\Yii3IdempotencyDb\RecordRowMapper;

#[CoversClass(RecordRowMapper::class)]
final class RecordRowMapperTest extends TestCase
{
    private RecordRowMapper $mapper;

    private ClockInterface $clock;

    #[\Override]
    protected function setUp(): void
    {
        $this->clock = $this->createStub(ClockInterface::class);
        $this->clock->method('now')->willReturn(new \DateTimeImmutable('2026-06-11 12:00:00'));
        $this->mapper = new RecordRowMapper(clock: $this->clock);
    }

    #[Test]
    public function mapsRowWithNativeTypes(): void
    {
        $record = $this->mapper->map($this->row());

        $this->assertSame('order-123', $record->key->value);
        $this->assertSame('abc123hash', $record->fingerprint->hash);
        $this->assertSame(200, $record->response->statusCode);
        $this->assertSame('{"status":"ok"}', $record->response->body);
        $this->assertSame(['Content-Type' => ['application/json']], $record->response->headers);
        $this->assertSame('2026-06-12 12:00:00', $record->expiresAt->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function mapsRowWithJsonHeaders(): void
    {
        $row = $this->row(headers: '{"X-Custom":["val1","val2"]}');

        $record = $this->mapper->map($row);

        $this->assertSame(['X-Custom' => ['val1', 'val2']], $record->response->headers);
    }

    #[Test]
    public function mapsRowWithEmptyStringHeaders(): void
    {
        $row = $this->row(headers: '');

        $record = $this->mapper->map($row);

        $this->assertSame([], $record->response->headers);
    }

    #[Test]
    public function mapsRowWithEmptyJsonObjectHeaders(): void
    {
        $row = $this->row(headers: '{}');

        $record = $this->mapper->map($row);

        $this->assertSame([], $record->response->headers);
    }

    #[Test]
    public function mapsRowWithNativeArrayHeaders(): void
    {
        $row = $this->row(headers: ['Content-Type' => ['text/html']]);

        $record = $this->mapper->map($row);

        $this->assertSame(['Content-Type' => ['text/html']], $record->response->headers);
    }

    #[Test]
    public function mapsRowWithStatusCodeAsString(): void
    {
        $row = $this->row(statusCode: '201');

        $record = $this->mapper->map($row);

        $this->assertSame(201, $record->response->statusCode);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
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

    /**
     * @param array<string, mixed> $row
     */
    #[DataProvider('invalidRowProvider')]
    #[Test]
    public function throwsOnInvalidRow(array $row, string $needle): void
    {
        $this->expectException(InvalidRecordRowException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($needle, '/') . '/');

        $this->mapper->map($row);
    }

    #[Test]
    public function throwsOnInvalidKeyValue(): void
    {
        $row = $this->row(key: '');

        $this->expectException(InvalidRecordRowException::class);
        $this->expectExceptionMessage('Invalid key in DB row');

        $this->mapper->map($row);
    }

    /**
     * @return array<string, mixed>
     */
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

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private static function without(array $row, string $column): array
    {
        unset($row[$column]);

        return $row;
    }
}
