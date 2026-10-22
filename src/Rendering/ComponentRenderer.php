<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Rendering;

use Ethernize\Core\Container\Container;
use Ethernize\Core\Http\HttpException;
use Ethernize\Pulse\Component;
use Ethernize\Pulse\ComponentFactory;
use Ethernize\Pulse\ComponentMetadata;
use Ethernize\Pulse\ComponentRegistry;
use Ethernize\Pulse\Exception\PulseException;
use Ethernize\Pulse\Http\PulseEndpoint;
use Ethernize\Pulse\Security\RevisionStore;
use Ethernize\Pulse\Security\Snapshot;
use Ethernize\Pulse\Security\SnapshotCodec;
use Ethernize\Pulse\Support\RequestContext;
use Ethernize\Weave\Exception\RenderException;
use Ethernize\Weave\Runtime\NativeComponents;
use Ethernize\Weave\Runtime\NativeOutput;
use Ethernize\Weave\Runtime\RegionRecorder;
use Ethernize\Weave\Runtime\Runtime;
use Ethernize\Weave\TemplateEngine;

/**
 * Renders components through Weave and builds their snapshots.
 *
 * It is registered as Weave's native component provider, so a registered
 * component is used in templates as a tag (`<Counter :start="5" />`). Nested
 * components get ids derived from their parent's id, the position of the tag
 * (including list item keys) and an optional `key` property, so the same use
 * of a component keeps the same identity between renders.
 */
final class ComponentRenderer implements NativeComponents
{
    /** @var list<string> ids of the components being rendered, innermost last */
    private array $stack = [];

    /** @var list<array<string, true>> per component being rendered: ids of the child components the browser already displays */
    private array $displayed = [];

    /** When true, children are mounted again even if the browser displays them (a resynchronization). */
    private bool $remount = false;

    public function __construct(
        private readonly Container $container,
        private readonly ComponentRegistry $registry,
        private readonly ComponentFactory $factory,
        private readonly SnapshotCodec $codec,
        private readonly RevisionStore $revisions,
        private readonly string $endpointPath,
        private readonly string $runtimePath,
    ) {
    }

    public function names(): array
    {
        return [...array_map(ComponentRegistry::pascal(...), $this->registry->names()), 'PulseScripts'];
    }

    /**
     * Child components are mounted again instead of reused. The browser asks for it when it cannot
     * find a child it was told to keep.
     */
    public function remountChildren(bool $remount): void
    {
        $this->remount = $remount;
    }

    public function render(string $name, array $props, string $identity, Runtime $runtime): string|NativeOutput
    {
        $context = $this->context();
        $context->markUsed();

        if ($name === 'PulseScripts') {
            return '<script src="' . htmlspecialchars($context->basePath() . $this->runtimePath . '?v=' . PulseEndpoint::runtimeVersion(), ENT_QUOTES | ENT_HTML5) . '" defer></script>';
        }

        $kebab = $this->registry->fromPascal($name) ?? throw new RenderException(sprintf('The component <%s> is not registered with Pulse.', $name));
        $key = $props['key'] ?? null;
        unset($props['key']);

        if ($key !== null && !is_int($key) && !is_string($key)) {
            throw new RenderException(sprintf('The key of <%s> must be a string or an integer.', $name));
        }

        $parent = $this->stack[count($this->stack) - 1] ?? null;
        $id = $parent === null
            ? bin2hex(random_bytes(16))
            : substr(hash('sha256', $parent . "\0" . $kebab . "\0" . $identity . "\0" . (string) $key), 0, 32);

        // A child the browser already displays keeps its state: nothing is mounted, and a placeholder
        // stands in for it wherever the parent's markup is sent. The browser puts the live element there.
        if ($parent !== null && !$this->remount && isset($this->displayed[count($this->displayed) - 1][$id])) {
            return new NativeOutput('<div data-pulse-ref="' . $id . '"></div>', $id);
        }

        $revision = $parent === null ? 0 : $this->revisions->current($id);

        $component = $this->factory->create($kebab, $id, $context->request());
        $metadata = $this->factory->metadata($kebab);
        $this->factory->mount($component, $props);
        $component->pulseBoot();

        try {
            $this->factory->assertViewable($component, $metadata, $context->request());
        } catch (PulseException) {
            throw new HttpException(403);
        }

        $rendered = $this->renderInstance($component, $metadata);
        $snapshot = new Snapshot($id, $kebab, $revision, $rendered['state'], RegionMap::fromRecorder($rendered['recorder']), SnapshotCodec::currentBucket());

        return new NativeOutput($this->wrap($kebab, $id, $this->codec->encode($snapshot, $context->id()), $rendered['html']), $id);
    }

    /**
     * Renders the component's template with its state, reporting regions.
     *
     * With the snapshot of the previous render, child components the browser already displays are
     * not mounted again (a placeholder stands in for them). With $incremental as well, parts of the
     * template that cannot have changed are left out (see SnapshotPrevious): `html` is then incomplete
     * and only the recorder is meaningful. Pass $incremental = false to get the whole component.
     *
     * @return array{html: string, recorder: RegionRecorder, state: array<string, mixed>}
     */
    public function renderInstance(Component $component, ComponentMetadata $metadata, ?Snapshot $previous = null, bool $incremental = true): array
    {
        $state = $metadata->export($component);
        $recorder = new RegionRecorder();

        /** @var TemplateEngine $engine */
        $engine = $this->container->get(TemplateEngine::class);

        $this->stack[] = $component->pulseId();
        $this->displayed[] = $previous === null ? [] : self::childrenIn($previous->regions);

        try {
            $html = $engine->renderInstrumented(
                $component->pulseTemplate(),
                [...$component->pulseWith(), ...$state, 'errors' => $component->errors],
                $recorder,
                $previous === null || !$incremental ? null : new SnapshotPrevious($previous->regions, $previous->state, $state),
            );
        } finally {
            array_pop($this->stack);
            array_pop($this->displayed);
        }

        return ['html' => $html, 'recorder' => $recorder, 'state' => $state];
    }

    /**
     * Ids of the child components a region map says are displayed.
     *
     * @param array<string, mixed> $regions
     * @return array<string, true>
     */
    private static function childrenIn(array $regions): array
    {
        $ids = [];

        foreach ($regions as $entry) {
            if (is_array($entry) && ($entry[0] ?? null) === 'n' && is_string($entry[1] ?? null) && preg_match('/^[a-f0-9]{32}$/', $entry[1]) === 1) {
                $ids[$entry[1]] = true;
            }
        }

        return $ids;
    }

    public function wrap(string $name, string $id, string $token, string $html): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');

        return sprintf(
            '<div data-pulse-id="%s" data-pulse-name="%s" data-pulse-endpoint="%s" data-pulse-snapshot="%s">%s</div>',
            $escape($id),
            $escape($name),
            $escape($this->context()->basePath() . $this->endpointPath),
            $escape($token),
            $html,
        );
    }

    public function context(): RequestContext
    {
        /** @var RequestContext */
        return $this->container->get(RequestContext::class);
    }
}
