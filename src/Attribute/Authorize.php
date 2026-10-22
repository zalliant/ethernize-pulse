<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Attribute;

use Attribute;

/**
 * Names an ability the current user must have to see the component at all. It is checked by the
 * registered ActionAuthorizer every time the component is mounted, hydrated from a snapshot or
 * refreshed, so a snapshot issued earlier never keeps access after a logout or a revoked permission.
 * Without an authorizer the component is denied. Components without this attribute (and without an
 * authorizeView() override) are public.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class Authorize
{
    public function __construct(public readonly string $ability)
    {
    }
}
