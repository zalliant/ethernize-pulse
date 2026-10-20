<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Rendering;

use Ethernize\Weave\Runtime\RegionRecorder;
use LogicException;

/**
 * The compact description of what a browser is displaying, built from the
 * regions of a render. It is what snapshots carry in place of HTML.
 *
 *   text / html                       ["t"|"h", hash]
 *   native component                  ["n", component id]
 *   opaque region (unkeyed @each)     ["o", hash]
 *   element attributes               ["a", {name: hash}]
 *   conditional block                ["b", branch]
 *   keyed list                       ["l", [encoded keys], hashes]
 *
 * `hashes` is one string holding a fixed-width hash (HASH_WIDTH characters) of the markup of each
 * item, in the order of the keys. A list item is compared as a whole: nothing inside it has an entry.
 */
final class RegionMap
{
    public const HASH_WIDTH = 8;

    private function __construct()
    {
    }

    /**
     * The map of a complete render, or, with $old, of an incremental one: items the render left out
     * keep their previous hash.
     *
     * @param array<string, mixed> $old
     * @return array<string, list<mixed>>
     */
    public static function fromRecorder(RegionRecorder $recorder, array $old = []): array
    {
        $map = [];

        foreach ($recorder->regions as $id => $region) {
            $entry = match ($region['k']) {
                't', 'h' => [$region['k'], self::hash((string) $region['v'])],
                'n' => ['n', $region['id'] ?? self::hash((string) $region['v'])],
                'o' => ['o', self::hash((string) $region['html'])],
                'a' => ['a', array_map(self::hash(...), $region['attrs'])],
                'b' => ['b', $region['branch']],
                'l' => ['l', $region['keys'], self::itemHashes($region['keys'], $region['items'], $old[$id] ?? null)],
                default => null,
            };

            if ($entry !== null) {
                $map[$id] = $entry;
            }
        }

        return $map;
    }

    /**
     * The map after an incremental render: the previous map, with the entries of every region that
     * was rendered this time replaced, and the entries of regions that no longer exist removed.
     *
     * Regions that were left out of the render (Weave's PreviousRender) are simply not in the
     * recorder, so their entries carry over. Old entries become obsolete when the region around them
     * is rendered again as a whole: a conditional block that shows another branch, and a list item that
     * is gone or was rendered again (only the identities of components inside items are kept per item).
     *
     * @param array<string, mixed> $old
     * @return array<string, list<mixed>>
     */
    public static function merge(array $old, RegionRecorder $new): array
    {
        $dead = [];

        foreach ($new->regions as $id => $region) {
            if ($region['k'] === 'l') {
                // Items are rendered as a whole, so what an item held before is replaced by what it holds now:
                // components inside it are noted again by this render, if they are still there.
                $before = $old[$id] ?? null;

                if (is_array($before) && ($before[0] ?? null) === 'l') {
                    foreach (array_unique([...array_diff((array) $before[1], $region['keys']), ...array_keys($region['items'])]) as $gone) {
                        $dead[$id . '/' . $gone . '/'] = true;
                    }
                }
            } elseif ($region['k'] === 'b') {
                $before = $old[$id] ?? null;

                if (is_array($before) && ($before[0] ?? null) === 'b' && $before[1] !== $region['branch']) {
                    $dead[$id . '.'] = true;
                    $dead[$id . '/'] = true;
                }
            }
        }

        if ($dead !== []) {
            foreach ($old as $id => $_) {
                // The dead prefixes end with their delimiter, so "a/MQ/" never matches "a/MQ.x/".
                for ($position = strlen((string) $id) - 1; $position > 0; $position--) {
                    $character = $id[$position];

                    if (($character === '.' || $character === '/') && isset($dead[substr((string) $id, 0, $position + 1)])) {
                        unset($old[$id]);
                        break;
                    }
                }
            }
        }

        return array_replace($old, self::fromRecorder($new, $old));
    }

    /**
     * A 48-bit hash of the markup of one list item, as HASH_WIDTH URL-safe characters. Items are
     * compared by this alone, so it is wider than the hash of a text region.
     */
    public static function itemHash(string $html): string
    {
        return strtr(substr(base64_encode(hash('xxh3', $html, true)), 0, self::HASH_WIDTH), '+/', '-_');
    }

    /**
     * The hashes stored for the items of a list entry, by key.
     *
     * @param array<int, mixed> $entry a map entry of kind "l"
     * @return array<string, string>
     */
    public static function hashesOf(array $entry): array
    {
        $hashes = [];
        $joined = (string) ($entry[2] ?? '');

        foreach (array_values((array) ($entry[1] ?? [])) as $index => $key) {
            $hashes[(string) $key] = substr($joined, $index * self::HASH_WIDTH, self::HASH_WIDTH);
        }

        return $hashes;
    }

    public static function hash(string $value): string
    {
        return substr(hash('xxh3', $value), 0, 8);
    }

    /**
     * @param list<string>           $keys
     * @param array<string, string>  $items  markup of the items that were rendered, by key
     * @param array<int, mixed>|null $before the previous entry of this list
     */
    private static function itemHashes(array $keys, array $items, ?array $before): string
    {
        // Nothing was rendered again and the list is the same: the hashes are the ones it had.
        if ($items === [] && is_array($before) && ($before[0] ?? null) === 'l' && $before[1] === $keys && is_string($before[2] ?? null)) {
            return $before[2];
        }

        $previous = null;
        $hashes = '';

        foreach ($keys as $key) {
            if (isset($items[$key])) {
                $hashes .= self::itemHash($items[$key]);
                continue;
            }

            // Left out of this render: it must have been displayed before, with this hash.
            $previous ??= is_array($before) && ($before[0] ?? null) === 'l' ? self::hashesOf($before) : [];

            if (!isset($previous[$key]) || strlen($previous[$key]) !== self::HASH_WIDTH) {
                throw new LogicException('A list item was left out of the render but has no previous hash.');
            }

            $hashes .= $previous[$key];
        }

        return $hashes;
    }
}
