<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests;

use Rasuvaeff\Yii3IdempotencyDb\ClaimDeadlineMap;
use Rasuvaeff\Yii3IdempotencyDb\ClaimDeadlines;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Lifecycle\BeforeTest;
use Testo\Test;

#[Test]
#[Covers(ClaimDeadlines::class)]
#[Covers(ClaimDeadlineMap::class)]
final class ClaimDeadlinesTest
{
    private const int MAX_ENTRIES = 1024;

    private ClaimDeadlines $deadlines;

    #[BeforeTest]
    public function setUp(): void
    {
        $this->deadlines = new ClaimDeadlines();
    }

    public function forgetReturnsTheRememberedDeadline(): void
    {
        $this->deadlines->remember('order-1', '2026-06-11 13:00:00');

        Assert::same($this->deadlines->forget('order-1'), '2026-06-11 13:00:00');
    }

    public function forgetReturnsNullForAnUnknownKey(): void
    {
        Assert::null($this->deadlines->forget('never-remembered'));
    }

    public function aKeyIsRememberedOnlyOnce(): void
    {
        // the deadline is spent when the claim ends: a second release must not
        // be able to present the same ownership token again
        $this->deadlines->remember('order-1', '2026-06-11 13:00:00');
        $this->deadlines->forget('order-1');

        Assert::null($this->deadlines->forget('order-1'));
    }

    public function rememberingAgainReplacesTheDeadline(): void
    {
        $this->deadlines->remember('order-1', '2026-06-11 13:00:00');
        $this->deadlines->remember('order-1', '2026-06-11 14:00:00');

        Assert::same($this->deadlines->forget('order-1'), '2026-06-11 14:00:00');
    }

    public function keysAreKeptApart(): void
    {
        $this->deadlines->remember('order-1', '2026-06-11 13:00:00');
        $this->deadlines->remember('order-2', '2026-06-11 14:00:00');

        Assert::same($this->deadlines->forget('order-2'), '2026-06-11 14:00:00');
        Assert::same($this->deadlines->forget('order-1'), '2026-06-11 13:00:00');
    }

    public function theOldestEntryIsEvictedOnceTheCapIsReached(): void
    {
        // a long-running worker must not accumulate a deadline per claim that
        // ended in neither store() nor release()
        for ($i = 1; $i <= self::MAX_ENTRIES; ++$i) {
            $this->deadlines->remember('key-' . $i, 'deadline-' . $i);
        }

        Assert::same($this->deadlines->forget('key-1'), 'deadline-1');
        $this->deadlines->remember('key-1', 'deadline-1');

        $this->deadlines->remember('overflow', 'deadline-overflow');

        Assert::null($this->deadlines->forget('key-2'));
        Assert::same($this->deadlines->forget('key-3'), 'deadline-3');
        Assert::same($this->deadlines->forget('overflow'), 'deadline-overflow');
    }
}
