<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Support;

use Closure;
use LogicException;
use Psr\Http\Message\ServerRequestInterface;

/**
 * What Pulse needs to know about the request being served. It is registered as
 * a scoped service: the container discards it at the end of every request, so
 * nothing about one user can reach the next.
 */
final class RequestContext
{
    private ?string $id = null;
    private string $basePath = '';
    private ?ServerRequestInterface $request = null;
    private bool $used = false;

    /**
     * @param (Closure(): ?ServerRequestInterface)|null $current resolves the request the route handler received
     */
    public function __construct(private readonly ?Closure $current = null)
    {
    }

    public function start(string $id, string $basePath, ServerRequestInterface $request): void
    {
        $this->id = $id;
        $this->basePath = $basePath;
        $this->request = $request;
        $this->used = false;
    }

    /**
     * The browser's anonymous context id (the Pulse cookie). Snapshots are bound to it.
     */
    public function id(): string
    {
        return $this->id ?? throw new LogicException('Pulse needs its context middleware. Register PulseProvider so the cookie middleware runs.');
    }

    public function isStarted(): bool
    {
        return $this->id !== null;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * The request components are mounted with. It is the one that reached the route handler, after every
     * middleware (Shield's authentication, for instance) enriched it, whatever the order the providers
     * were registered in. The copy Pulse saw at the start is only the fallback.
     */
    public function request(): ?ServerRequestInterface
    {
        return ($this->current !== null ? ($this->current)() : null) ?? $this->request;
    }

    /**
     * Records that this response contains per-user Pulse state, so it must not be cached.
     */
    public function markUsed(): void
    {
        $this->used = true;
    }

    public function wasUsed(): bool
    {
        return $this->used;
    }
}
