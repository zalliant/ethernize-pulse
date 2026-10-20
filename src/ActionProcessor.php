<?php

declare(strict_types=1);

namespace Ethernize\Pulse;

use Ethernize\Core\Container\Container;
use Ethernize\Pulse\Exception\PulseException;
use Ethernize\Pulse\Exception\ValidationFailed;
use Ethernize\Pulse\Rendering\ComponentRenderer;
use Ethernize\Pulse\Rendering\Differ;
use Ethernize\Pulse\Rendering\RegionMap;
use Ethernize\Pulse\Security\ActionAuthorizer;
use Ethernize\Pulse\Security\RevisionStore;
use Ethernize\Pulse\Security\Snapshot;
use Ethernize\Pulse\Security\SnapshotCodec;
use Ethernize\Pulse\Support\Json;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Executes one action against one component instance, following a fixed order:
 *
 *  1. verify the snapshot (signature, context, version, age, size)
 *  2. claim its revision, which rejects replays and out-of-order requests
 *  3. restore the state, validating every value against the declared types
 *  4. apply form inputs, only to properties declared as #[Input]
 *  5. look the action up in the declared list; authorize it; convert its arguments
 *  6. run it, render again and compute the updates
 *
 * Names received from the browser are only ever used as keys into the lists
 * built from attributes, never as method or class names.
 */
final class ActionProcessor
{
    public const PROTOCOL = 1;

    /** Built-in action: apply the inputs and render, without calling a method. */
    public const REFRESH = '$refresh';

    public function __construct(
        private readonly Container $container,
        private readonly ComponentFactory $factory,
        private readonly ComponentRenderer $renderer,
        private readonly SnapshotCodec $codec,
        private readonly RevisionStore $revisions,
        private readonly Differ $differ,
        private readonly int $maxArguments = 8,
        private readonly int $maxInputs = 100,
    ) {
    }

    /**
     * @param list<mixed>          $arguments
     * @param array<string, mixed> $inputs
     * @param bool                 $full   ask for the whole component instead of patches (used to resynchronize)
     * @param bool                 $remount mount child components again instead of keeping the ones the browser displays
     * @param string|null          $key     identifier of this call, made by the browser (see Component::actionKey())
     * @return array<string, mixed> the JSON response
     * @throws PulseException
     */
    public function process(string $token, string $action, array $arguments, array $inputs, string $context, ?ServerRequestInterface $request, bool $full = false, bool $remount = false, ?string $key = null): array
    {
        if (count($arguments) > $this->maxArguments || count($inputs) > $this->maxInputs) {
            throw new PulseException(PulseException::TOO_LARGE, 'The request has too many values.', 413);
        }

        Json::assertSafe([$arguments, $inputs], $this->codec->maxDepth(), $this->codec->maxNodes());

        $snapshot = $this->codec->decode($token, $context);
        $metadata = $this->factory->metadata($snapshot->name);

        if (!$this->revisions->claim($snapshot->id, $snapshot->revision)) {
            throw PulseException::stale();
        }

        $component = $this->factory->create($snapshot->name, $snapshot->id, $request);
        $this->factory->hydrate($component, $metadata, $snapshot->state);
        $component->errors = [];
        $component->pulseUseActionKey($key);
        $component->pulseBoot();
        $this->factory->applyInputs($component, $metadata, $inputs);

        $this->run($component, $metadata, $action, $arguments, $request);

        // Render only what can have changed. The complete component is rendered when the browser asks
        // for it, and when the diff cannot be trusted (the old map and the new render disagree).
        $this->renderer->remountChildren($remount);

        try {
            $rendered = $this->renderer->renderInstance($component, $metadata, $snapshot, !$full);
            $diff = $full ? ['patches' => [], 'full' => true, 'strategy' => 3] : $this->differ->diff($snapshot->regions, $rendered['recorder']);

            if ($diff['full'] && !$full) {
                $rendered = $this->renderer->renderInstance($component, $metadata, $snapshot, false);
            }
        } finally {
            $this->renderer->remountChildren(false);
        }

        $regions = $diff['full'] ? RegionMap::fromRecorder($rendered['recorder']) : RegionMap::merge($snapshot->regions, $rendered['recorder']);
        $next = $snapshot->next($rendered['state'], $regions, SnapshotCodec::currentBucket());

        $response = [
            'v' => self::PROTOCOL,
            'id' => $snapshot->id,
            'snapshot' => $this->codec->encode($next, $context),
            'strategy' => $diff['strategy'],
        ];

        // The server is authoritative for form fields: it returns the value of every #[Input] after the action.
        $model = [];

        foreach ($metadata->state as $name => $definition) {
            if ($definition['writable']) {
                $model[$name] = $rendered['state'][$name];
            }
        }

        if ($model !== []) {
            $response['model'] = $model;
        }

        if ($diff['full'] || $full) {
            $response['mode'] = 'full';
            $response['html'] = $rendered['html'];
        } else {
            $response['mode'] = $diff['patches'] === [] ? 'none' : 'patch';
            $response['patches'] = $diff['patches'];
        }

        return $response;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function run(Component $component, ComponentMetadata $metadata, string $action, array $arguments, ?ServerRequestInterface $request): void
    {
        if ($action === self::REFRESH) {
            return;
        }

        $definition = $metadata->actions[$action] ?? null;

        if ($definition === null) {
            throw new PulseException(PulseException::UNKNOWN_ACTION, 'This action does not exist.', 403);
        }

        $converted = $this->factory->arguments($definition, $arguments);

        if (!$component->authorize($action, $converted)) {
            throw PulseException::forbidden();
        }

        if ($definition['ability'] !== null) {
            $authorizer = $this->container->has(ActionAuthorizer::class) ? $this->container->get(ActionAuthorizer::class) : null;

            if (!$authorizer instanceof ActionAuthorizer || !$authorizer->allows($definition['ability'], $component, $converted, $request)) {
                throw PulseException::forbidden();
            }
        }

        try {
            $component->{$action}(...$converted);
        } catch (ValidationFailed) {
            // The messages are in $component->errors; the component renders with them.
        }
    }
}
