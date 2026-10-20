<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Attribute;

use Attribute;

/**
 * Marks a public scalar property (string, int, float or bool) as state that
 * form fields may write through `pulse:model`. Everything else about it works
 * like #[State]. Incoming values are validated against the declared type, and
 * strings are limited to $max characters. Always validate the content in the
 * action before using it: the type check does not make a value correct.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Input
{
    public function __construct(public readonly int $max = 10000)
    {
    }
}
