<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Security;

use InvalidArgumentException;

/**
 * Signing keys. New snapshots are signed with the current key; previous keys
 * are only accepted for verification, which allows rotation without breaking
 * pages that are already open.
 */
final class KeyRing
{
    public const MIN_BYTES = 32;

    /** @var list<string> */
    private readonly array $previous;

    /**
     * @param list<string> $previous
     */
    public function __construct(private readonly string $current, array $previous = [])
    {
        foreach ([$current, ...$previous] as $key) {
            if (strlen($key) < self::MIN_BYTES) {
                throw new InvalidArgumentException(sprintf('Pulse keys must have at least %d bytes.', self::MIN_BYTES));
            }
        }

        $this->previous = array_values($previous);
    }

    /**
     * Accepts raw strings or "base64:..." values, as produced by `php -r "echo 'base64:' . base64_encode(random_bytes(32));"`.
     */
    public static function parse(string $value): string
    {
        if (str_starts_with($value, 'base64:')) {
            $decoded = base64_decode(substr($value, 7), true);

            if ($decoded === false) {
                throw new InvalidArgumentException('The Pulse key is not valid base64.');
            }

            return $decoded;
        }

        return $value;
    }

    public function current(): string
    {
        return $this->current;
    }

    /**
     * @return list<string> current key first
     */
    public function all(): array
    {
        return [$this->current, ...$this->previous];
    }
}
