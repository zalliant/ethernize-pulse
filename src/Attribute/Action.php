<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Attribute;

use Attribute;

/**
 * Marks a public method as callable from the browser. Only methods with this
 * attribute can be invoked; the name received from the client is looked up in
 * the list of declared actions and never used to call anything directly.
 *
 * $authorize names an ability checked by the registered ActionAuthorizer
 * before the method runs. If an ability is declared and no authorizer is
 * registered, the action is denied.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Action
{
    public function __construct(public readonly ?string $authorize = null)
    {
    }
}
