<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb;

use Psr\Clock\ClockInterface;
use Rasuvaeff\Yii3Idempotency\IdempotencyFingerprint;
use Rasuvaeff\Yii3Idempotency\IdempotencyKey;
use Rasuvaeff\Yii3Idempotency\IdempotencyRecord;
use Rasuvaeff\Yii3Idempotency\IdempotencyResponse;
use Rasuvaeff\Yii3IdempotencyDb\Exception\InvalidRecordRowException;

/**
 * @internal
 */
final readonly class RecordRowMapper
{
    public function __construct(
        private ClockInterface $clock,
    ) {}

    /**
     * @param array<array-key, mixed> $row
     */
    public function map(array $row): IdempotencyRecord
    {
        $keyValue = $this->extractString(row: $row, column: 'key');
        $fingerprintHash = $this->extractString(row: $row, column: 'fingerprint');
        $statusCode = $this->extractInt(row: $row, column: 'status_code');
        $body = $this->extractString(row: $row, column: 'body');
        $expiresAt = $this->extractString(row: $row, column: 'expires_at');

        try {
            $key = new IdempotencyKey(value: $keyValue);
        } catch (\InvalidArgumentException $e) {
            throw new InvalidRecordRowException(
                message: sprintf('Invalid key in DB row: %s', $e->getMessage()),
                previous: $e,
            );
        }

        $headers = $this->extractHeaders(row: $row);

        $expiresAtDate = $this->parseExpiresAt(expiresAt: $expiresAt);

        return new IdempotencyRecord(
            key: $key,
            fingerprint: new IdempotencyFingerprint(hash: $fingerprintHash),
            response: new IdempotencyResponse(
                statusCode: $statusCode,
                headers: $headers,
                body: $body,
            ),
            expiresAt: $expiresAtDate,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function extractHeaders(array $row): array
    {
        if (!isset($row['headers'])) {
            throw new InvalidRecordRowException(
                message: 'Missing column "headers" in idempotency record row',
            );
        }

        if (\is_string($row['headers'])) {
            if ($row['headers'] === '') {
                return [];
            }

            try {
                $decoded = json_decode(json: $row['headers'], associative: true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidRecordRowException(
                    message: sprintf('Invalid "headers" JSON: %s', $e->getMessage()),
                    previous: $e,
                );
            }

            return $this->validateHeaders(headers: $decoded);
        }

        if (\is_array($row['headers'])) {
            return $this->validateHeaders(headers: $row['headers']);
        }

        throw new InvalidRecordRowException(
            message: sprintf(
                'Invalid column "headers" in idempotency record row: expected string or array, got %s',
                get_debug_type($row['headers']),
            ),
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function validateHeaders(mixed $headers): array
    {
        if (!\is_array($headers)) {
            throw new InvalidRecordRowException(
                message: sprintf('Invalid "headers": expected array, got %s', get_debug_type($headers)),
            );
        }

        $result = [];

        foreach ($headers as $name => $values) {
            if (!\is_string($name)) {
                throw new InvalidRecordRowException(
                    message: sprintf('Invalid header name: expected string, got %s', get_debug_type($name)),
                );
            }

            if (!\is_array($values)) {
                throw new InvalidRecordRowException(
                    message: sprintf('Invalid header "%s" values: expected array, got %s', $name, get_debug_type($values)),
                );
            }

            $validatedValues = [];

            foreach ($values as $i => $value) {
                if (!\is_string($value)) {
                    throw new InvalidRecordRowException(
                        message: sprintf('Invalid header "%s" value at index %d: expected string, got %s', $name, $i, get_debug_type($value)),
                    );
                }

                $validatedValues[] = $value;
            }

            $result[$name] = $validatedValues;
        }

        return $result;
    }

    private function parseExpiresAt(string $expiresAt): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($expiresAt);
        } catch (\Exception $e) {
            throw new InvalidRecordRowException(
                message: sprintf('Invalid "expires_at" datetime: %s', $expiresAt),
                previous: $e,
            );
        }
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractString(array $row, string $column): string
    {
        if (!isset($row[$column]) || !\is_string($row[$column])) {
            throw new InvalidRecordRowException(
                message: sprintf('Missing or invalid column "%s" in idempotency record row', $column),
            );
        }

        return $row[$column];
    }

    /**
     * @param array<array-key, mixed> $row
     */
    private function extractInt(array $row, string $column): int
    {
        if (!isset($row[$column])) {
            throw new InvalidRecordRowException(
                message: sprintf('Missing or invalid column "%s" in idempotency record row', $column),
            );
        }

        if (\is_int($row[$column])) {
            return $row[$column];
        }

        if (\is_string($row[$column]) && preg_match('/^-?\d+$/', $row[$column]) === 1) {
            return (int) $row[$column];
        }

        throw new InvalidRecordRowException(
            message: sprintf('Missing or invalid column "%s" in idempotency record row', $column),
        );
    }
}
