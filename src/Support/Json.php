<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Support;

use Ethernize\Pulse\Exception\PulseException;

/**
 * Validation of values that travel in snapshots and requests: only null,
 * booleans, finite numbers, valid UTF-8 strings and arrays of those, within
 * depth and size limits. Objects and resources can never be state.
 */
final class Json
{
    private function __construct()
    {
    }

    /**
     * @throws PulseException
     */
    public static function assertSafe(mixed $value, int $maxDepth, int $maxNodes): void
    {
        $nodes = 0;
        self::walk($value, 0, $maxDepth, $maxNodes, $nodes);
    }

    /**
     * Number of values in $value, counting every nested array and element (the root counts as one).
     * Runs in C, so it is the check to use on large state.
     *
     * @param array<mixed> $value
     */
    public static function countNodes(array $value): int
    {
        return 1 + count($value, COUNT_RECURSIVE);
    }

    /**
     * @param array<mixed> $value data that came out of json_decode(): it can only hold null, booleans,
     *                            numbers, valid UTF-8 strings and arrays, within the depth given to json_decode
     * @throws PulseException
     */
    public static function assertDecoded(array $value, int $maxNodes): void
    {
        if (self::countNodes($value) > $maxNodes) {
            throw new PulseException(PulseException::TOO_LARGE, 'The value is too large.', 413);
        }
    }

    /**
     * Encodes component state, enforcing what assertSafe() enforces (valid UTF-8, finite numbers, no
     * objects, depth, node count) without visiting every value from PHP: json_encode() checks text,
     * numbers and depth in C, the nodes are counted in C, and objects, which json_encode() would
     * silently turn into arrays, are looked for in serialize()'s output. Only when that output hints
     * at an object is the exact walk used to decide.
     *
     * @param array<string, mixed> $payload   what to encode
     * @param array<mixed>         $state     the part of $payload that is component state
     * @throws PulseException
     */
    public static function encodeState(array $payload, array $state, int $maxDepth, int $maxNodes, int $flags): string
    {
        if (self::countNodes($state) > $maxNodes) {
            throw new PulseException(PulseException::TOO_LARGE, 'The value is too large.', 413);
        }

        if (preg_match('/(?:^|[;{}])O:\d/', serialize($state)) === 1) {
            self::assertSafe($state, $maxDepth, $maxNodes);
        }

        try {
            // The payload wraps the state in one more array.
            return json_encode($payload, $flags | JSON_THROW_ON_ERROR, $maxDepth + 1);
        } catch (\JsonException $exception) {
            // Name the problem the way assertSafe() would, and let it be the one to decide.
            self::assertSafe($state, $maxDepth, $maxNodes);

            throw new PulseException(PulseException::INVALID_INPUT, 'Unsupported value.');
        }
    }

    private static function walk(mixed $value, int $depth, int $maxDepth, int $maxNodes, int &$nodes): void
    {
        if (++$nodes > $maxNodes) {
            throw new PulseException(PulseException::TOO_LARGE, 'The value is too large.', 413);
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new PulseException(PulseException::INVALID_INPUT, 'Numbers must be finite.');
            }

            return;
        }

        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                throw new PulseException(PulseException::INVALID_INPUT, 'Text must be valid UTF-8.');
            }

            return;
        }

        if (!is_array($value)) {
            throw new PulseException(PulseException::INVALID_INPUT, 'Unsupported value type.');
        }

        if ($depth >= $maxDepth) {
            throw new PulseException(PulseException::TOO_LARGE, 'The value is nested too deeply.', 413);
        }

        foreach ($value as $key => $item) {
            if (!is_int($key) && !is_string($key)) {
                throw new PulseException(PulseException::INVALID_INPUT, 'Invalid array key.');
            }

            self::walk($item, $depth + 1, $maxDepth, $maxNodes, $nodes);
        }
    }

    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $data): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $data) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
