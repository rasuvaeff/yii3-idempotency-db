<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb;

/**
 * Remembers the `expires_at` value each claim wrote, so a later delete can tell
 * "the claim I took" from "a claim someone else took after mine went stale".
 *
 * The deadline doubles as an ownership token: a takeover happens only after the
 * original deadline has passed, and `claimTtlSeconds` is at least one second, so
 * the new owner necessarily writes a later deadline. A late release from the
 * previous owner therefore matches no row.
 *
 * Entries are dropped on `store()` and `release()`; the cap bounds the map in a
 * long-running worker where a claim occasionally ends in neither.
 *
 * @internal
 */
final class ClaimDeadlines
{
    private const int MAX_ENTRIES = 1024;

    /**
     * @var array<string, string>
     */
    private array $deadlines = [];

    public function remember(string $key, string $deadline): void
    {
        if (\count($this->deadlines) >= self::MAX_ENTRIES) {
            array_shift($this->deadlines);
        }

        $this->deadlines[$key] = $deadline;
    }

    /**
     * The deadline this instance wrote for the key, if any, dropping it.
     */
    public function forget(string $key): ?string
    {
        $deadline = $this->deadlines[$key] ?? null;

        unset($this->deadlines[$key]);

        return $deadline;
    }
}
