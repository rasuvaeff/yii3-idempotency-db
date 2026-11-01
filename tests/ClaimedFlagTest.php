<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests;

use Rasuvaeff\Yii3IdempotencyDb\DbIdempotencyStorage;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(DbIdempotencyStorage::class)]
final class ClaimedFlagTest
{
    #[DataProvider('flagProvider')]
    public function theClaimedColumnIsReadWhateverTheDriverReturns(mixed $value, bool $expected): void
    {
        $storage = (new \ReflectionClass(DbIdempotencyStorage::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DbIdempotencyStorage::class, 'isClaimedRow');

        Assert::same($method->invoke($storage, ['claimed' => $value]), $expected);
    }

    public static function flagProvider(): iterable
    {
        yield 'true' => [true, true];
        yield 'false' => [false, false];
        yield 'integer one' => [1, true];
        yield 'integer zero' => [0, false];
        yield 'integer two' => [2, false];
        yield 'string one' => ['1', true];
        yield 'string t' => ['t', true];
        yield 'string true' => ['true', true];
        yield 'string zero' => ['0', false];
        yield 'string f' => ['f', false];
        yield 'null' => [null, false];
        yield 'float one' => [1.0, false];
    }

    public function aRowWithoutTheClaimedColumnIsNotClaimed(): void
    {
        $storage = (new \ReflectionClass(DbIdempotencyStorage::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(DbIdempotencyStorage::class, 'isClaimedRow');

        Assert::false($method->invoke($storage, []));
    }
}
