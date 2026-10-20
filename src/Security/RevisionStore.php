<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Security;

/**
 * Remembers the last accepted revision of each component instance, which is
 * what makes replays and out-of-order requests detectable without sessions.
 *
 * A snapshot with revision N is accepted exactly once: claim($id, N) succeeds
 * only if nothing is stored for the instance and N is 0, or the stored value
 * is N. On success the stored value becomes N + 1. Implementations must make
 * this check-and-set atomic.
 */
interface RevisionStore
{
    public function claim(string $id, int $expected): bool;

    /**
     * The revision the next accepted request must carry (0 for unknown instances).
     */
    public function current(string $id): int;
}
