<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Security;

use Ethernize\Pulse\Component;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Decides whether an action that declares an ability may run. Register an
 * implementation in the container under this interface; the authentication
 * module will provide one that reads the signed-in user from the request.
 *
 * Pulse fails closed: an action with #[Action(authorize: '...')] is denied
 * when no authorizer is registered.
 */
interface ActionAuthorizer
{
    /**
     * @param list<bool|int|float|string|null> $arguments already validated action arguments
     */
    public function allows(string $ability, Component $component, array $arguments, ?ServerRequestInterface $request): bool;
}
