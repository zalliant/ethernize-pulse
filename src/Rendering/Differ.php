<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Rendering;

use Ethernize\Weave\Runtime\RegionRecorder;

/**
 * Computes the smallest set of updates that turns what the browser displays
 * (the region map in the snapshot) into the new render.
 *
 * Strategies, from cheapest to most expensive:
 *   S0  nothing changed: no patches
 *   S1  text and attribute values of dynamic regions
 *   S2  keyed lists: removals, insertions and moves of items, with unchanged items untouched
 *   S3  localized replacement of a region whose structure changed (a different @if branch,
 *       trusted HTML, a nested component) or of a list item whose markup changed, applied
 *       with a morph in the browser
 *
 * When the old map and the new render disagree about the kind of a region the
 * diff cannot be trusted and the whole component is replaced ("full").
 *
 * Patches (protocol v1):
 *   {op:"text", id, value}              raw text, set with textContent
 *   {op:"attr", id, set:{}, remove:[]}  attributes of the element anchored by data-pa="id"
 *   {op:"html", id, html}               replace the content of an html or component region
 *   {op:"block", id, html}              replace the content of a conditional block
 *   {op:"list", id, remove:[keys], place:[{key, before, html?}], replace:[{key, html}]}
 *                                        items are placed (html for new ones only) and the content of
 *                                        items whose markup changed is replaced
 */
final class Differ
{
    private const TEXT = 1;
    private const LIST = 2;
    private const BLOCK = 3;

    /**
     * @param array<string, mixed> $old region map from the snapshot
     * @return array{patches: list<array<string, mixed>>, full: bool, strategy: int}
     */
    public function diff(array $old, RegionRecorder $new): array
    {
        $patches = [];
        $skipped = [];
        $level = 0;

        foreach ($new->regions as $id => $region) {
            // Components noted inside list items or opaque regions are part of that bigger unit.
            if (($region['inner'] ?? false) || $this->isUnderSkipped($new, $region['p'] ?? null, $skipped)) {
                continue;
            }

            $before = $old[$id] ?? null;
            $kind = $region['k'];

            if (!is_array($before) || ($before[0] ?? null) !== $kind) {
                return ['patches' => [], 'full' => true, 'strategy' => self::BLOCK];
            }

            switch ($kind) {
                case 't':
                    if ($before[1] !== RegionMap::hash((string) $region['v'])) {
                        $patches[] = ['op' => 'text', 'id' => $id, 'value' => $region['v']];
                        $level = max($level, self::TEXT);
                    }

                    break;

                case 'h':
                    if ($before[1] !== RegionMap::hash((string) $region['v'])) {
                        $patches[] = ['op' => 'html', 'id' => $id, 'html' => $region['v']];
                        $level = max($level, self::BLOCK);
                    }

                    break;

                case 'n':
                    // A component is replaced only when it is another instance; a displayed one keeps itself.
                    if ($before[1] !== ($region['id'] ?? RegionMap::hash((string) $region['v']))) {
                        $patches[] = ['op' => 'html', 'id' => $id, 'html' => $region['v']];
                        $level = max($level, self::BLOCK);
                    }

                    break;

                case 'a':
                    $patch = $this->attributes($id, (array) $before[1], $region['attrs']);

                    if ($patch !== null) {
                        $patches[] = $patch;
                        $level = max($level, self::TEXT);
                    }

                    break;

                case 'o':
                    if ($before[1] !== RegionMap::hash((string) $region['html'])) {
                        $patches[] = ['op' => 'html', 'id' => $id, 'html' => $region['html']];
                        $level = max($level, self::BLOCK);
                    }

                    break;

                case 'b':
                    if ($before[1] !== $region['branch']) {
                        $patches[] = ['op' => 'block', 'id' => $id, 'html' => $region['html']];
                        $skipped[$id] = true;
                        $level = max($level, self::BLOCK);
                    }

                    break;

                case 'l':
                    if (!is_string($before[2] ?? null)) {
                        return ['patches' => [], 'full' => true, 'strategy' => self::BLOCK];
                    }

                    $patch = $this->list($id, $before, $region['keys'], $region['items']);

                    if ($patch !== null) {
                        $patches[] = $patch;
                        $level = max($level, ($patch['replace'] ?? []) === [] ? self::LIST : self::BLOCK);
                    }

                    break;
            }
        }

        return ['patches' => $patches, 'full' => false, 'strategy' => $level];
    }

    /**
     * @param array<string, true> $skipped
     */
    private function isUnderSkipped(RegionRecorder $new, ?string $parent, array $skipped): bool
    {
        while ($parent !== null) {
            if (isset($skipped[$parent])) {
                return true;
            }

            $parent = $new->regions[$parent]['p'] ?? null;
        }

        return false;
    }

    /**
     * @param array<string, string> $old    attribute name => hash
     * @param array<string, string> $fragments attribute name => rendered fragment
     * @return array<string, mixed>|null
     */
    private function attributes(string $id, array $old, array $fragments): ?array
    {
        $set = [];
        $remove = [];

        foreach ($fragments as $name => $fragment) {
            if (($old[$name] ?? null) === RegionMap::hash($fragment)) {
                continue;
            }

            if ($fragment === '') {
                $remove[] = $name;
                continue;
            }

            if (preg_match('/^ ([^\s=]+)(?:="(.*)")?$/s', $fragment, $match) !== 1) {
                continue;
            }

            $set[$name] = isset($match[2]) ? htmlspecialchars_decode($match[2], ENT_QUOTES | ENT_HTML5) : '';
        }

        if ($set === [] && $remove === []) {
            return null;
        }

        return ['op' => 'attr', 'id' => $id, 'set' => $set === [] ? new \stdClass() : $set, 'remove' => $remove];
    }

    /**
     * @param array<int, mixed>     $before the previous map entry of the list
     * @param list<string>          $newKeys
     * @param array<string, string> $items  markup of the items that were rendered, by key (the others were left out)
     * @return array<string, mixed>|null
     */
    private function list(string $id, array $before, array $newKeys, array $items): ?array
    {
        $oldKeys = (array) $before[1];
        $oldPositions = null;
        $place = [];
        $remove = [];

        if ($oldKeys !== $newKeys) {
            // Items at the start and at the end that did not move are left out of the search: most edits
            // (an insertion, a removal, one item moved) touch a short stretch in the middle. Those items
            // can always be part of the longest increasing run, so the result is as small as it would be
            // for the whole list.
            $oldCount = count($oldKeys);
            $newCount = count($newKeys);
            $limit = min($oldCount, $newCount);
            $head = 0;

            while ($head < $limit && $oldKeys[$head] === $newKeys[$head]) {
                $head++;
            }

            $tail = 0;

            while ($tail < $limit - $head && $oldKeys[$oldCount - 1 - $tail] === $newKeys[$newCount - 1 - $tail]) {
                $tail++;
            }

            $oldMiddle = array_slice($oldKeys, $head, $oldCount - $head - $tail);
            $newMiddle = array_slice($newKeys, $head, $newCount - $head - $tail);
            $oldPositions = array_flip($oldMiddle);
            $common = array_values(array_filter($newMiddle, static fn (string $key): bool => isset($oldPositions[$key])));
            $stay = [];

            foreach ($this->longestIncreasing(array_map(static fn (string $key): int => $oldPositions[$key], $common)) as $index) {
                $stay[$common[$index]] = true;
            }

            $next = $tail > 0 ? $newKeys[$newCount - $tail] : null;

            for ($i = count($newMiddle) - 1; $i >= 0; $i--) {
                $key = $newMiddle[$i];

                if (!isset($oldPositions[$key])) {
                    $place[] = ['key' => $key, 'before' => $next, 'html' => $items[$key]];
                } elseif (!isset($stay[$key])) {
                    $place[] = ['key' => $key, 'before' => $next];
                }

                $next = $key;
            }

            $remove = array_values(array_diff($oldMiddle, $newMiddle));
        }

        // Items that stayed but were rendered again: replace their content when their markup changed.
        $replace = [];
        $positions = null;

        foreach ($items as $key => $html) {
            $positions ??= array_flip($oldKeys);

            if (!isset($positions[$key])) {
                continue; // a new item: it was sent with its markup
            }

            $previous = substr((string) $before[2], $positions[$key] * RegionMap::HASH_WIDTH, RegionMap::HASH_WIDTH);

            if ($previous !== RegionMap::itemHash($html)) {
                $replace[] = ['key' => (string) $key, 'html' => $html];
            }
        }

        if ($remove === [] && $place === [] && $replace === []) {
            return null;
        }

        $patch = ['op' => 'list', 'id' => $id, 'remove' => $remove, 'place' => $place];

        if ($replace !== []) {
            $patch['replace'] = $replace;
        }

        return $patch;
    }
    /**
     * Indexes of a longest strictly increasing subsequence.
     *
     * @param list<int> $values
     * @return list<int>
     */
    private function longestIncreasing(array $values): array
    {
        $tails = [];
        $tailIndex = [];
        $previous = [];

        foreach ($values as $index => $value) {
            $low = 0;
            $high = count($tails);

            while ($low < $high) {
                $middle = intdiv($low + $high, 2);

                if ($tails[$middle] < $value) {
                    $low = $middle + 1;
                } else {
                    $high = $middle;
                }
            }

            $tails[$low] = $value;
            $tailIndex[$low] = $index;
            $previous[$index] = $low > 0 ? $tailIndex[$low - 1] : null;
        }

        $result = [];
        $cursor = $tails === [] ? null : $tailIndex[count($tails) - 1];

        while ($cursor !== null) {
            $result[] = $cursor;
            $cursor = $previous[$cursor];
        }

        return array_reverse($result);
    }
}
