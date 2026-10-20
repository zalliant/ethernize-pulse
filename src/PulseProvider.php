<?php

declare(strict_types=1);

namespace Ethernize\Pulse;

use Ethernize\Core\Application;
use Ethernize\Core\BootableProvider;
use Ethernize\Core\Config\Environment;
use Ethernize\Core\Container\Container;
use Ethernize\Pulse\Http\ContextMiddleware;
use Ethernize\Pulse\Http\PulseEndpoint;
use Ethernize\Pulse\Rendering\ComponentRenderer;
use Ethernize\Pulse\Rendering\Differ;
use Ethernize\Pulse\Security\FileRevisionStore;
use Ethernize\Pulse\Security\KeyRing;
use Ethernize\Pulse\Security\RevisionStore;
use Ethernize\Pulse\Security\SnapshotCodec;
use Ethernize\Pulse\Support\RequestContext;
use Ethernize\Weave\Runtime\NativeComponents;
use InvalidArgumentException;

/**
 * Registers Pulse. Requires WeaveProvider.
 *
 * Configuration (config/pulse.php):
 *   components   map of component name (kebab-case) to class
 *   key_env      name of the environment variable holding the signing key ("base64:..." or at
 *                least 32 raw bytes). Preferred: the value is read when needed and never stored
 *                in the Manifest.
 *   key          the key itself. The Manifest freezes configuration into a file, so a literal key
 *                ends up in that file; use key_env for anything that is built with the Manifest.
 *                One of the two is required outside local/development/testing; there a random
 *                key is created in var/pulse/
 *   previous_keys  older keys still accepted for verification (rotation)
 *   path         endpoint prefix (default "/_pulse"); the runtime is served at "<path>/runtime.js"
 *   max_request_bytes   (default 131072)
 *   max_snapshot_bytes  (default 524288)
 *   max_depth           (default 8), max_nodes (default 50000)
 *   max_age_hours       snapshot lifetime (default 12)
 *
 * Register an implementation of Security\ActionAuthorizer in the container to
 * authorize actions declared with #[Action(authorize: '...')].
 */
final class PulseProvider implements BootableProvider
{
    private const DEV_ENVIRONMENTS = ['local', 'development', 'testing'];

    public function register(Container $container, Application $app): void
    {
        $config = $app->config();
        $registry = new ComponentRegistry((array) $config->get('pulse.components', []));
        $path = '/' . trim((string) $config->get('pulse.path', '/_pulse'), '/');

        if (preg_match('~^/[A-Za-z0-9_/.-]+$~', $path) !== 1 || str_contains($path, '..')) {
            throw new InvalidArgumentException('pulse.path is not a valid path prefix.');
        }

        $container->instance(ComponentRegistry::class, $registry);
        $container->scoped(RequestContext::class, static fn (): RequestContext => new RequestContext());
        $container->singleton(SnapshotCodec::class, fn (): SnapshotCodec => new SnapshotCodec(
            $this->keys($app),
            (int) $config->get('pulse.max_snapshot_bytes', 524288),
            (int) $config->get('pulse.max_depth', 8),
            (int) $config->get('pulse.max_nodes', 50000),
            (int) $config->get('pulse.max_age_hours', 12),
        ));
        // Bind your own RevisionStore before this provider runs to keep revisions somewhere else.
        if (!$container->isBound(RevisionStore::class)) {
            // A revision may only be forgotten once every snapshot of its instance has expired, or an old
            // snapshot would be accepted again: keep them for longer than a snapshot lives.
            $container->singleton(RevisionStore::class, fn (): RevisionStore => new FileRevisionStore(
                $app->basePath('var/pulse/revisions'),
                max(172800, ((int) $config->get('pulse.max_age_hours', 12) + 12) * 3600),
            ));
        }
        $container->singleton(ComponentFactory::class, static fn (Container $c): ComponentFactory => new ComponentFactory(
            $c,
            $registry,
            (int) $config->get('pulse.max_depth', 8),
            (int) $config->get('pulse.max_nodes', 50000),
        ));
        $container->singleton(Differ::class, static fn (): Differ => new Differ());
        $container->singleton(ComponentRenderer::class, static fn (Container $c): ComponentRenderer => new ComponentRenderer(
            $c,
            $registry,
            $c->get(ComponentFactory::class),
            $c->get(SnapshotCodec::class),
            $c->get(RevisionStore::class),
            $path,
            $path . '/runtime.js',
        ));
        $container->singleton(NativeComponents::class, static fn (Container $c): object => $c->get(ComponentRenderer::class));
        $container->singleton(ActionProcessor::class, static fn (Container $c): ActionProcessor => new ActionProcessor(
            $c,
            $c->get(ComponentFactory::class),
            $c->get(ComponentRenderer::class),
            $c->get(SnapshotCodec::class),
            $c->get(RevisionStore::class),
            $c->get(Differ::class),
        ));
        $container->singleton(PulseEndpoint::class, static fn (Container $c): PulseEndpoint => new PulseEndpoint(
            $c,
            $c->get(ActionProcessor::class),
            (int) $config->get('pulse.max_request_bytes', 131072),
        ));
        $container->singleton(ContextMiddleware::class, static fn (Container $c): ContextMiddleware => new ContextMiddleware($c));
    }

    public function boot(Application $app): void
    {
        $path = '/' . trim((string) $app->config()->get('pulse.path', '/_pulse'), '/');

        $app->router()->on($path)->post([PulseEndpoint::class, 'handle'])->name('pulse.endpoint');
        $app->router()->on($path . '/runtime.js')->get([PulseEndpoint::class, 'runtime'])->name('pulse.runtime');
        $app->addMiddleware(ContextMiddleware::class);
    }

    private function environment(Application $app, string $name): ?string
    {
        // With the Manifest only the real process environment counts, like APP_MANIFEST itself.
        if ($app->isCompiled()) {
            $value = getenv($name);

            if ($value === false) {
                $value = $_SERVER[$name] ?? null;
            }

            return is_string($value) ? $value : null;
        }

        return Environment::load($app->basePath())->get($name);
    }

    private function keys(Application $app): KeyRing
    {
        $config = $app->config();
        $value = $config->get('pulse.key');
        $variable = $config->get('pulse.key_env');

        if (is_string($variable) && $variable !== '') {
            $value = $this->environment($app, $variable);

            if ($value === null || $value === '') {
                if (!in_array($app->environment(), self::DEV_ENVIRONMENTS, true)) {
                    throw new InvalidArgumentException(sprintf('Set the environment variable %s: it holds the Pulse signing key.', $variable));
                }

                $value = null; // development falls back to a generated key below
            }
        }

        $previous = array_map(static fn (mixed $key): string => KeyRing::parse((string) $key), (array) $config->get('pulse.previous_keys', []));

        if (is_string($value) && $value !== '') {
            return new KeyRing(KeyRing::parse($value), $previous);
        }

        if (!in_array($app->environment(), self::DEV_ENVIRONMENTS, true)) {
            throw new InvalidArgumentException('Set pulse.key: Pulse needs a signing key of at least 32 bytes outside local, development and testing.');
        }

        $file = $app->basePath('var/pulse/dev.key');

        if (!is_file($file)) {
            if (!is_dir(dirname($file)) && !mkdir(dirname($file), 0775, true) && !is_dir(dirname($file))) {
                throw new InvalidArgumentException('Could not create var/pulse.');
            }

            file_put_contents($file, bin2hex(random_bytes(32)), LOCK_EX);
            @chmod($file, 0600);
        }

        return new KeyRing(trim((string) file_get_contents($file)), $previous);
    }
}
