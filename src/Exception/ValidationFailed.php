<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Exception;

use RuntimeException;

/**
 * Thrown by Component::validate() after it stored the messages in the
 * component's errors. Pulse catches it, skips the rest of the action and
 * renders the component with the errors; the typed values are kept.
 */
final class ValidationFailed extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Validation failed.');
    }
}
