<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests;

use InvalidArgumentException;
use Rasuvaeff\Yii3IdempotencyDb\IdempotencyKeysTableName;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(IdempotencyKeysTableName::class)]
final class IdempotencyKeysTableNameTest
{
    public function defaultsToTheDocumentedName(): void
    {
        Assert::same((new IdempotencyKeysTableName())->value, 'idempotency_keys');
        Assert::same((string) new IdempotencyKeysTableName(), 'idempotency_keys');
    }

    public function acceptsASchemaQualifiedName(): void
    {
        Assert::same((new IdempotencyKeysTableName('public.idempotency_keys'))->value, 'public.idempotency_keys');
    }

    public function indexBaseFlattensTheSchemaSeparator(): void
    {
        // a dot cannot appear in an index name
        Assert::same((new IdempotencyKeysTableName('public.idempotency_keys'))->forIndexName(), 'public_idempotency_keys');
        Assert::same((new IdempotencyKeysTableName('idempotency_keys'))->forIndexName(), 'idempotency_keys');
    }

    #[DataProvider('invalidNamesProvider')]
    public function rejectsAnythingOutsideTheIdentifierWhitelist(string $name): void
    {
        Expect::exception(InvalidArgumentException::class);

        new IdempotencyKeysTableName($name);
    }

    public static function invalidNamesProvider(): iterable
    {
        yield 'empty' => [''];
        yield 'starts with digit' => ['1table'];
        yield 'space' => ['my table'];
        yield 'semicolon injection' => ['t; DROP TABLE users'];
        yield 'dash' => ['my-table'];
        yield 'two dots' => ['a.b.c'];
        // PCRE's $ also matches before a trailing newline — the pattern is
        // anchored with \z so this is rejected
        yield 'trailing newline' => ["idempotency_keys\n"];
    }
}
