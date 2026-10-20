<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Exception;

use RuntimeException;

/**
 * A Pulse request cannot be honored. The code and message are safe to send to
 * the client; they never include classes, paths or internal state.
 */
class PulseException extends RuntimeException
{
    public const BAD_REQUEST = 'bad_request';
    public const INVALID_SNAPSHOT = 'invalid_snapshot';
    public const EXPIRED = 'expired';
    public const STALE = 'stale';
    public const FORBIDDEN = 'forbidden';
    public const UNKNOWN_ACTION = 'unknown_action';
    public const TOO_LARGE = 'too_large';
    public const INVALID_INPUT = 'invalid_input';

    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }

    public static function stale(): self
    {
        return new self(self::STALE, 'This component is out of date. Reload the page.', 409);
    }

    public static function invalidSnapshot(): self
    {
        return new self(self::INVALID_SNAPSHOT, 'The component state is invalid. Reload the page.', 400);
    }

    public static function forbidden(): self
    {
        return new self(self::FORBIDDEN, 'This action is not allowed.', 403);
    }
}
