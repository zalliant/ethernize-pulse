<?php

declare(strict_types=1);

namespace Ethernize\Pulse;

use Ethernize\Core\Container\Container;
use Ethernize\Pulse\Exception\PulseException;
use Ethernize\Pulse\Security\ActionAuthorizer;
use Ethernize\Pulse\Support\Json;
use Ethernize\Pulse\Validation\Validator;
use InvalidArgumentException;
use Psr\Http\Message\ServerRequestInterface;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Creates components and moves values in and out of them under strict rules.
 *
 * Values restored from a snapshot must match the declared types exactly (the
 * snapshot is signed, but the code may have changed since). Values coming from
 * the browser (inputs and action arguments) are coerced from the textual forms
 * a DOM can produce, and are rejected when they do not fit.
 */
final class ComponentFactory
{
    public function __construct(
        private readonly Container $container,
        private readonly ComponentRegistry $registry,
        private readonly int $maxDepth = 8,
        private readonly int $maxNodes = 50000,
    ) {
    }

    public function metadata(string $name): ComponentMetadata
    {
        $class = $this->registry->class($name) ?? throw new PulseException(PulseException::BAD_REQUEST, 'Unknown component.', 404);

        return ComponentMetadata::for($class);
    }

    public function create(string $name, string $id, ?ServerRequestInterface $request): Component
    {
        $class = $this->registry->class($name) ?? throw new PulseException(PulseException::BAD_REQUEST, 'Unknown component.', 404);

        $this->container->allow($class);

        /** @var Component $component */
        $component = $this->container->get($class);
        $component->pulseAttach($id, $name, $request);
        $component->pulseUseValidator($this->container->has(Validator::class) ? $this->container->get(Validator::class) : new Validator());

        // A form property starts as an empty form unless mount() or the snapshot says otherwise.
        foreach (ComponentMetadata::for($class)->state as $property => $definition) {
            if ($definition['type'] === 'form' && !(new ReflectionProperty($component, $property))->isInitialized($component)) {
                (new ReflectionProperty($component, $property))->setValue($component, new ($definition['class'])());
            }
        }

        return $component;
    }

    /**
     * Decides whether the current request may see the component: the class-level ability and the
     * authorizeView() hook. It runs on mount, on every action and on refresh, always against the request
     * being served, never against the identity that was present when a snapshot was issued.
     *
     * @throws PulseException forbidden
     */
    public function assertViewable(Component $component, ComponentMetadata $metadata, ?ServerRequestInterface $request): void
    {
        if ($metadata->ability !== null) {
            $authorizer = $this->container->has(ActionAuthorizer::class) ? $this->container->get(ActionAuthorizer::class) : null;

            if (!$authorizer instanceof ActionAuthorizer || !$authorizer->allows($metadata->ability, $component, [], $request)) {
                throw PulseException::forbidden();
            }
        }

        if (!$component->pulseAuthorizeView()) {
            throw PulseException::forbidden();
        }
    }

    /**
     * Calls mount() with the properties given in the template tag, if the
     * component defines it.
     *
     * @param array<string, mixed> $props
     */
    public function mount(Component $component, array $props): void
    {
        if (!method_exists($component, 'mount')) {
            if ($props !== []) {
                throw new InvalidArgumentException(sprintf('<%s> received properties but its class has no mount() method.', ComponentRegistry::pascal($component->pulseName())));
            }

            return;
        }

        $arguments = [];

        foreach ((new ReflectionMethod($component, 'mount'))->getParameters() as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $props)) {
                $arguments[$name] = $props[$name];
                unset($props[$name]);
            } elseif (!$parameter->isDefaultValueAvailable() && !$parameter->allowsNull()) {
                throw new InvalidArgumentException(sprintf('<%s> is missing the property "%s".', ComponentRegistry::pascal($component->pulseName()), $name));
            }
        }

        if ($props !== []) {
            throw new InvalidArgumentException(sprintf('<%s> does not accept the property "%s".', ComponentRegistry::pascal($component->pulseName()), array_key_first($props)));
        }

        $component->mount(...$arguments);
    }

    /**
     * Restores state from a verified snapshot. Properties that are no longer
     * declared are ignored; declared ones must have the declared type.
     *
     * @param array<string, mixed> $state
     * @throws PulseException
     */
    public function hydrate(Component $component, ComponentMetadata $metadata, array $state): void
    {
        // The snapshot was verified and decoded with json_decode(), which already enforced valid text and the
        // depth limit; the number of values is the one limit left to check.
        Json::assertDecoded($state, $this->maxNodes);

        foreach ($metadata->state as $name => $definition) {
            if (!array_key_exists($name, $state)) {
                continue;
            }

            $value = $state[$name];

            if ($value === null) {
                if (!$definition['nullable']) {
                    throw PulseException::invalidSnapshot();
                }
            } elseif (!match ($definition['type']) {
                'int' => is_int($value),
                'float' => is_float($value) || is_int($value),
                'string' => is_string($value),
                'bool' => is_bool($value),
                'array', 'form' => is_array($value),
            }) {
                throw PulseException::invalidSnapshot();
            }

            (new ReflectionProperty($component, $name))->setValue($component, match (true) {
                $definition['type'] === 'form' => $definition['class']::fromState($value),
                $definition['type'] === 'float' && is_int($value) => (float) $value,
                default => $value,
            });
        }
    }

    /**
     * Applies values sent by form fields to #[Input] properties. A property
     * that is not declared as an input cannot be written, whatever its name.
     *
     * @param array<string, mixed> $updates
     * @throws PulseException
     */
    public function applyInputs(Component $component, ComponentMetadata $metadata, array $updates): void
    {
        foreach ($updates as $name => $value) {
            // "form.name" and "form.lines.0.qty": a field of a form property, checked by the form itself.
            if (str_contains((string) $name, '.')) {
                [$property, $rest] = explode('.', (string) $name, 2);
                $form = $metadata->state[$property] ?? null;

                if ($form === null || $form['type'] !== 'form' || !$form['writable']) {
                    throw PulseException::forbidden();
                }

                $reflection = new ReflectionProperty($component, $property);
                $reflection->getValue($component)->set(explode('.', $rest), $value);
                continue;
            }

            $definition = $metadata->state[(string) $name] ?? null;

            if ($definition === null || !$definition['writable']) {
                throw PulseException::forbidden();
            }

            $coerced = self::coerce($value, $definition['type'], $definition['nullable'], true);

            if (is_string($coerced) && mb_strlen($coerced) > $definition['max']) {
                throw new PulseException(PulseException::INVALID_INPUT, 'A value is too long.', 422);
            }

            (new ReflectionProperty($component, (string) $name))->setValue($component, $coerced);
        }
    }

    /**
     * Validates and converts action arguments against the method signature.
     *
     * @param list<mixed> $arguments
     * @param array{params: list<array{name: string, type: string, nullable: bool, hasDefault: bool, default: mixed}>} $action
     * @return list<bool|int|float|string|null>
     * @throws PulseException
     */
    public function arguments(array $action, array $arguments): array
    {
        if (count($arguments) > count($action['params'])) {
            throw new PulseException(PulseException::INVALID_INPUT, 'Too many arguments.', 422);
        }

        $converted = [];

        foreach ($action['params'] as $index => $parameter) {
            if (array_key_exists($index, $arguments)) {
                $converted[] = self::coerce($arguments[$index], $parameter['type'], $parameter['nullable'], true);
            } elseif ($parameter['hasDefault']) {
                $converted[] = $parameter['default'];
            } else {
                throw new PulseException(PulseException::INVALID_INPUT, 'Missing argument.', 422);
            }
        }

        return $converted;
    }

    /**
     * @throws PulseException
     */
    public static function coerce(mixed $value, string $type, bool $nullable, bool $fromDom): bool|int|float|string|null
    {
        if ($value === null) {
            if ($nullable) {
                return null;
            }

            throw new PulseException(PulseException::INVALID_INPUT, 'A required value is missing.', 422);
        }

        $result = match ($type) {
            'string' => is_string($value) ? $value : (is_int($value) || is_float($value) ? (string) $value : null),
            'int' => is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d{1,18}$/', $value) === 1 ? (int) $value : null),
            'float' => is_int($value) || is_float($value) ? (float) $value : (is_string($value) && is_numeric($value) && preg_match('/^-?\d+(\.\d+)?([eE][-+]?\d+)?$/', $value) === 1 ? (float) $value : null),
            'bool' => is_bool($value) ? $value : (is_string($value) ? match (strtolower($value)) {
                'true', '1', 'on', 'yes' => true,
                'false', '0', 'off', 'no', '' => false,
                default => null,
            } : (is_int($value) && ($value === 0 || $value === 1) ? $value === 1 : null)),
            default => null,
        };

        if ($result === null) {
            if ($nullable && $value === '') {
                return null;
            }

            throw new PulseException(PulseException::INVALID_INPUT, 'A value has the wrong type.', 422);
        }

        if (is_float($result) && !is_finite($result)) {
            throw new PulseException(PulseException::INVALID_INPUT, 'Numbers must be finite.', 422);
        }

        return $result;
    }
}
