<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Security;

/**
 * The state of one component instance as sent to the browser.
 *
 * $regions holds a compact description of what the browser is currently
 * displaying for each dynamic region (hashes, list keys, block branches), so
 * the server can compute minimal updates without storing any HTML.
 */
final class Snapshot
{
    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $regions
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly int $revision,
        public readonly array $state,
        public readonly array $regions,
        public readonly int $bucket,
    ) {
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $regions
     */
    public function next(array $state, array $regions, int $bucket): self
    {
        return new self($this->id, $this->name, $this->revision + 1, $state, $regions, $bucket);
    }
}
