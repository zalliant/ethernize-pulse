<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Http;

use Ethernize\Core\Container\Container;
use Ethernize\Core\Http\Response;
use Ethernize\Pulse\ActionProcessor;
use Ethernize\Pulse\Exception\PulseException;
use Ethernize\Pulse\Support\RequestContext;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The HTTP surface of Pulse: one POST endpoint that runs actions and one GET
 * route that serves the JavaScript runtime.
 *
 * Requests must be JSON, carry the header "X-Pulse: 1" (which cross-site
 * forms cannot send), and, when the browser sends an Origin header, come from
 * the same host. Beyond that, the signed snapshot bound to the browser's
 * cookie is what authorizes a request: without a snapshot issued to this
 * browser, nothing can be run.
 *
 * Errors are reported as {v, error: {code, message}} with a matching status.
 * Unexpected exceptions propagate to the Core error handler (generic 500).
 */
final class PulseEndpoint
{
    public function __construct(
        private readonly Container $container,
        private readonly ActionProcessor $processor,
        private readonly int $maxRequestBytes,
    ) {
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $started = hrtime(true);

        try {
            $data = $this->execute($request);

            return $this->json($data, 200)->withHeader('Server-Timing', sprintf('pulse;dur=%.2f', (hrtime(true) - $started) / 1e6));
        } catch (PulseException $exception) {
            return $this->json([
                'v' => ActionProcessor::PROTOCOL,
                'error' => ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
            ], $exception->status);
        }
    }

    /**
     * A short identifier of the runtime file, added to its URL (`runtime.js?v=...`). A new runtime
     * therefore gets a new URL, so a browser that still has the old one cached for up to an hour can
     * never run it against a server that speaks the new protocol.
     */
    public static function runtimeVersion(): string
    {
        static $version = null;

        return $version ??= substr(hash_file('xxh3', dirname(__DIR__, 2) . '/resources/runtime.js'), 0, 12);
    }

    public function runtime(ServerRequestInterface $request): ResponseInterface
    {
        $source = (string) file_get_contents(dirname(__DIR__, 2) . '/resources/runtime.js');
        $etag = '"' . substr(hash('xxh3', $source), 0, 16) . '"';

        if ($request->getHeaderLine('If-None-Match') === $etag) {
            return Response::empty(304)->withHeader('ETag', $etag);
        }

        return Response::text($source)
            ->withHeader('ETag', $etag)
            ->withHeader('Cache-Control', 'public, max-age=3600')
            ->withHeader('Content-Type', 'application/javascript; charset=UTF-8');
    }

    /**
     * @return array<string, mixed>
     */
    private function execute(ServerRequestInterface $request): array
    {
        $type = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));

        if ($type !== 'application/json') {
            throw new PulseException(PulseException::BAD_REQUEST, 'Expected a JSON request.', 415);
        }

        if ($request->getHeaderLine('X-Pulse') !== '1') {
            throw new PulseException(PulseException::BAD_REQUEST, 'Not a Pulse request.', 400);
        }

        $this->assertSameOrigin($request);

        $body = $request->getBody();

        if (($body->getSize() ?? 0) > $this->maxRequestBytes) {
            throw new PulseException(PulseException::TOO_LARGE, 'The request is too large.', 413);
        }

        if ($body->isSeekable()) {
            $body->rewind();
        }

        // A PSR-7 read() may return fewer bytes than asked for, so read until the end,
        // but never more than the limit plus one byte.
        $raw = '';

        while (!$body->eof() && strlen($raw) <= $this->maxRequestBytes) {
            $chunk = $body->read($this->maxRequestBytes + 1 - strlen($raw));

            if ($chunk === '') {
                break;
            }

            $raw .= $chunk;
        }

        if (strlen($raw) > $this->maxRequestBytes) {
            throw new PulseException(PulseException::TOO_LARGE, 'The request is too large.', 413);
        }

        try {
            $data = json_decode($raw, true, 12, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new PulseException(PulseException::BAD_REQUEST, 'The request body is not valid JSON.');
        }

        if (
            !is_array($data)
            || ($data['v'] ?? null) !== ActionProcessor::PROTOCOL
            || !is_string($data['snapshot'] ?? null)
            || !is_string($data['action'] ?? null)
            || preg_match('/^(\$refresh|[A-Za-z_][A-Za-z0-9_]{0,63})$/', $data['action']) !== 1
            || !is_array($data['args'] ?? [])
            || !array_is_list($data['args'] ?? [])
            || !is_array($data['inputs'] ?? [])
            || (isset($data['key']) && (!is_string($data['key']) || preg_match('/^[A-Za-z0-9_-]{1,64}$/', $data['key']) !== 1))
        ) {
            throw new PulseException(PulseException::BAD_REQUEST, 'The request is malformed.');
        }

        /** @var RequestContext $context */
        $context = $this->container->get(RequestContext::class);

        return $this->processor->process($data['snapshot'], $data['action'], $data['args'] ?? [], $data['inputs'] ?? [], $context->id(), $request, ($data['full'] ?? false) === true, ($data['remount'] ?? false) === true, $data['key'] ?? null);
    }

    private function assertSameOrigin(ServerRequestInterface $request): void
    {
        $origin = $request->getHeaderLine('Origin');

        if ($origin === '') {
            return;
        }

        $parts = parse_url($origin);
        $uri = $request->getUri();
        $host = strtolower($uri->getHost());
        $scheme = $uri->getScheme();
        $port = $uri->getPort() ?? ($scheme === 'https' ? 443 : 80);
        $originPort = ($parts['port'] ?? null) ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80);

        if ($parts === false || strtolower((string) ($parts['host'] ?? '')) !== $host || $originPort !== $port) {
            throw PulseException::forbidden();
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(array $data, int $status): ResponseInterface
    {
        return Response::json($data, $status, ['Cache-Control' => 'no-store']);
    }
}
