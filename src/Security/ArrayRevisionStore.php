<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Security;

/**
 * In-memory store, for tests and single-process tools. It does not survive
 * the process, so it must not be used to serve real requests.
 */
final class ArrayRevisionStore implements RevisionStore
{
    /** @var array<string, int> */
    private array $revisions = [];

    public function claim(string $id, int $expected): bool
    {
        if (($this->revisions[$id] ?? 0) !== $expected) {
            return false;
        }

        $this->revisions[$id] = $expected + 1;

        return true;
    }

    public function current(string $id): int
    {
        return $this->revisions[$id] ?? 0;
    }
}
