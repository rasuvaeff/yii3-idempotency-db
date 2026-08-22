<?php

declare(strict_types=1);

namespace Rasuvaeff\Yii3IdempotencyDb;

/**
 * The mutable state behind {@see ClaimDeadlines}, kept in its own class so the
 * facade over it can stay `final readonly`.
 *
 * @internal
 */
final class ClaimDeadlineMap
{
    private const int MAX_ENTRIES = 1024;

    /**
     * @var array<string, string>
     */
    private array $entries = [];

    public function set(string $key, string $deadline): void
    {
        if (\count($this->entries) >= self::MAX_ENTRIES) {
            array_shift($this->entries);
        }

        $this->entries[$key] = $deadline;
    }

    /**
     * The deadline this instance wrote for the key, if any, dropping it.
     */
    public function remove(string $key): ?string
    {
        $deadline = $this->entries[$key] ?? null;

        unset($this->entries[$key]);

        return $deadline;
    }
}
