<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Attribute;

use Attribute;

/**
 * Marks a public property as component state: it is stored in the signed
 * snapshot, given to the template, and restored on every action. The client
 * can read it but cannot change it. To let a form field write to a property,
 * use #[Input] instead.
 *
 * Supported types: int, float, string, bool and array of those, each also nullable.
 * Properties without this attribute are never serialized and never touched by the client.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class State
{
}
