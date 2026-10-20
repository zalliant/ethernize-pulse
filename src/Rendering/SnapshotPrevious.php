<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Rendering;

use Ethernize\Weave\Runtime\PreviousRender;

/**
 * What the last render of a component looked like, taken from its verified snapshot, so Weave can
 * leave out the parts that cannot have changed.
 *
 * Only state properties are vouched for: they are in the snapshot, so the old value is known and
 * the new one is compared with `===`. Everything else a template can read (values returned by
 * with(), validation errors, loop variables of an enclosing list) is reported as changed, which
 * makes Weave render the parts that read it.
 */
final class SnapshotPrevious implements PreviousRender
{
    /** @var array<string, bool> */
    private array $verdicts = [];

    /**
     * @param array<string, mixed> $regions  region map of the previous render
     * @param array<string, mixed> $previous state of the previous render
     * @param array<string, mixed> $current  state now
     */
    public function __construct(
        private readonly array $regions,
        private readonly array $previous,
        private readonly array $current,
    ) {
    }

    public function unchanged(string $variable): bool
    {
        if (isset($this->verdicts[$variable])) {
            return $this->verdicts[$variable];
        }

        // "errors" is always supplied by the component, whatever the state holds.
        return $this->verdicts[$variable] = $variable !== 'errors'
            && array_key_exists($variable, $this->previous)
            && array_key_exists($variable, $this->current)
            && $this->previous[$variable] === $this->current[$variable];
    }

    public function previousValue(string $variable): mixed
    {
        return $this->previous[$variable] ?? null;
    }

    public function branch(string $regionId): ?int
    {
        $entry = $this->regions[$regionId] ?? null;

        return is_array($entry) && ($entry[0] ?? null) === 'b' && is_int($entry[1] ?? null) ? $entry[1] : null;
    }

    public function listKeys(string $regionId): ?array
    {
        $entry = $this->regions[$regionId] ?? null;

        return is_array($entry) && ($entry[0] ?? null) === 'l' && is_array($entry[1] ?? null) ? $entry[1] : null;
    }
}
