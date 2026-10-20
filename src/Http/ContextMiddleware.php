<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Http;

use Ethernize\Core\Container\Container;
use Ethernize\Core\Http\RequestAttributes;
use Ethernize\Pulse\Support\RequestContext;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Gives every browser that uses a Pulse component an anonymous context (an
 * HttpOnly, SameSite=Lax cookie holding a random id) and exposes it to the rest
 * of the request. Pages without Pulse components never receive the cookie.
 * Snapshots are signed together with this id, so a snapshot only verifies in
 * the browser it was issued to.
 *
 * Responses that contain Pulse state are marked "private, no-store", because
 * they embed signed per-browser snapshots.
 */
final class ContextMiddleware implements MiddlewareInterface
{
    public const COOKIE = 'pulse_ctx';

    public function __construct(private readonly Container $container)
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;
        $fresh = !is_string($cookie) || preg_match('/^[a-f0-9]{48}$/', $cookie) !== 1;
        $id = $fresh ? bin2hex(random_bytes(24)) : $cookie;

        $base = $request->getAttribute(RequestAttributes::BASE_PATH, '');

        /** @var RequestContext $context */
        $context = $this->container->get(RequestContext::class);
        $context->start($id, is_string($base) ? $base : '', $request);

        $response = $handler->handle($request);

        // The cookie is only issued when a component was actually rendered for this browser.
        if ($fresh && $context->wasUsed()) {
            $response = $response->withAddedHeader('Set-Cookie', sprintf(
                '%s=%s; Path=/; HttpOnly; SameSite=Lax%s',
                self::COOKIE,
                $id,
                $request->getUri()->getScheme() === 'https' ? '; Secure' : '',
            ));
        }

        if ($context->wasUsed() && !$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'private, no-store');
        }

        return $response;
    }
}
