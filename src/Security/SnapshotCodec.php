<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Security;

use Ethernize\Pulse\Exception\PulseException;
use Ethernize\Pulse\Support\Json;
use JsonException;

/**
 * Signs and verifies snapshots.
 *
 * Token format: payload "." base64url(HMAC-SHA256), where the payload is
 * base64url(json), or "z" followed by base64url(deflate(json)) when zlib is
 * available and the JSON is larger than COMPRESS_FROM bytes (region maps of
 * long lists are very repetitive and shrink a lot). The signature covers
 * the payload, the protocol version and the request context (the browser's
 * Pulse cookie), so a snapshot copied to another browser or user never
 * verifies. The signature is checked before the payload is parsed, and size
 * and depth limits are applied before decoding.
 *
 * Snapshots carry an hour-granularity issue time and expire after $maxAgeHours.
 * The coarse granularity keeps the token of an unchanged component identical
 * between renders.
 */
final class SnapshotCodec
{
    public const VERSION = 1;

    private const COMPRESS_FROM = 1024;

    public function __construct(
        private readonly KeyRing $keys,
        private readonly int $maxBytes = 524288,
        private readonly int $maxDepth = 8,
        private readonly int $maxNodes = 50000,
        private readonly int $maxAgeHours = 12,
    ) {
    }

    public static function currentBucket(?int $now = null): int
    {
        return intdiv($now ?? time(), 3600);
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function maxDepth(): int
    {
        return $this->maxDepth;
    }

    public function maxNodes(): int
    {
        return $this->maxNodes;
    }

    /**
     * @throws PulseException when the state is too large or not serializable
     */
    public function encode(Snapshot $snapshot, string $context): string
    {
        $json = Json::encodeState([
            'v' => self::VERSION,
            'i' => $snapshot->id,
            'n' => $snapshot->name,
            'r' => $snapshot->revision,
            's' => $snapshot->state === [] ? new \stdClass() : $snapshot->state,
            'g' => $snapshot->regions === [] ? new \stdClass() : $snapshot->regions,
            't' => $snapshot->bucket,
        ], $snapshot->state, $this->maxDepth, $this->maxNodes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        // Level 1: on this kind of JSON it compresses as well as level 6 in less than half the time.
        $deflated = strlen($json) > self::COMPRESS_FROM && function_exists('gzdeflate') ? gzdeflate($json, 1) : false;
        $payload = $deflated !== false ? 'z' . Json::base64UrlEncode($deflated) : Json::base64UrlEncode($json);

        $token = $payload . '.' . Json::base64UrlEncode($this->sign($payload, $context, $this->keys->current()));

        if (strlen($token) > $this->maxBytes) {
            throw new PulseException(PulseException::TOO_LARGE, 'The component state is too large.', 413);
        }

        return $token;
    }

    /**
     * @throws PulseException
     */
    public function decode(string $token, string $context, ?int $now = null): Snapshot
    {
        if ($token === '' || strlen($token) > $this->maxBytes) {
            throw $token === '' ? PulseException::invalidSnapshot() : new PulseException(PulseException::TOO_LARGE, 'The component state is too large.', 413);
        }

        $separator = strrpos($token, '.');

        if ($separator === false) {
            throw PulseException::invalidSnapshot();
        }

        $payload = substr($token, 0, $separator);
        $signature = Json::base64UrlDecode(substr($token, $separator + 1));

        if ($signature === null || strlen($signature) !== 32) {
            throw PulseException::invalidSnapshot();
        }

        $valid = false;

        foreach ($this->keys->all() as $key) {
            $valid = hash_equals($this->sign($payload, $context, $key), $signature) || $valid;
        }

        if (!$valid) {
            throw PulseException::invalidSnapshot();
        }

        $json = $this->unpack($payload);

        try {
            $data = $json === null ? null : json_decode($json, true, $this->maxDepth + 1, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw PulseException::invalidSnapshot();
        }

        if (
            !is_array($data)
            || ($data['v'] ?? null) !== self::VERSION
            || !is_string($data['i'] ?? null) || preg_match('/^[a-f0-9]{32}$/', $data['i']) !== 1
            || !is_string($data['n'] ?? null) || preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/', $data['n']) !== 1
            || !is_int($data['r'] ?? null) || $data['r'] < 0
            || !is_int($data['t'] ?? null)
            || !is_array($data['s'] ?? null)
            || !is_array($data['g'] ?? null)
        ) {
            throw PulseException::invalidSnapshot();
        }

        if (self::currentBucket($now) - $data['t'] > $this->maxAgeHours || $data['t'] - self::currentBucket($now) > 1) {
            throw new PulseException(PulseException::EXPIRED, 'This page has expired. Reload it.', 410);
        }

        return new Snapshot($data['i'], $data['n'], $data['r'], $data['s'], $data['g'], $data['t']);
    }

    /**
     * Runs only after the signature was verified. The inflated size is capped
     * anyway, so a leaked key cannot be turned into a memory bomb.
     */
    private function unpack(string $payload): ?string
    {
        if (!str_starts_with($payload, 'z')) {
            return Json::base64UrlDecode($payload);
        }

        $deflated = Json::base64UrlDecode(substr($payload, 1));

        if ($deflated === null || !function_exists('gzinflate')) {
            return null;
        }

        $inflated = @gzinflate($deflated, $this->maxBytes * 8);

        return $inflated === false ? null : $inflated;
    }

    private function sign(string $payload, string $context, string $key): string
    {
        return hash_hmac('sha256', 'ethernize-pulse-snapshot-v' . self::VERSION . "\0" . $context . "\0" . $payload, $key, true);
    }
}
