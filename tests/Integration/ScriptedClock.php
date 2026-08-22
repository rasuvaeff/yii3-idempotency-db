<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb\Tests\Integration;

use Psr\Clock\ClockInterface;

/**
 * A clock whose every reading is scripted by call number.
 *
 * `load()` reads the clock once to judge the row it just selected and once more
 * to build the conditional delete that follows. A script can therefore change
 * the row in between — deterministically reproducing the competitor that wins
 * the race between those two statements, which no amount of sleeping could.
 */
final class ScriptedClock implements ClockInterface
{
    private int $calls = 0;

    /**
     * @param \Closure(int): \DateTimeImmutable $script
     */
    public function __construct(
        private readonly \Closure $script,
    ) {}

    #[\Override]
    public function now(): \DateTimeImmutable
    {
        return ($this->script)(++$this->calls);
    }
}
