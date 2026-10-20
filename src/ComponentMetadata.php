<?php

declare(strict_types=1);

namespace Ethernize\Pulse;

use Ethernize\Pulse\Attribute\Action;
use Ethernize\Pulse\Attribute\Input;
use Ethernize\Pulse\Attribute\State;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * What Pulse knows about a component class, extracted once by reflection and
 * validated strictly: state properties and actions must be declared with
 * attributes and use supported types. Everything not declared here is
 * unreachable from the client.
 */
final class ComponentMetadata
{
    private const STATE_TYPES = ['int', 'float', 'string', 'bool', 'array'];
    private const SCALAR_TYPES = ['int', 'float', 'string', 'bool'];

    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param array<string, array{type: string, nullable: bool, writable: bool, max: int}> $state
     * @param array<string, array{params: list<array{name: string, type: string, nullable: bool, hasDefault: bool, default: mixed}>, ability: ?string}> $actions
     */
    private function __construct(
        public readonly string $class,
        public readonly array $state,
        public readonly array $actions,
    ) {
    }

    /**
     * @param class-string<Component> $class
     * @throws InvalidArgumentException when the component declares something Pulse cannot handle safely
     */
    public static function for(string $class): self
    {
        return self::$cache[$class] ??= self::build($class);
    }

    /**
     * @param class-string<Component> $class
     */
    private static function build(string $class): self
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isSubclassOf(Component::class) || !$reflection->isInstantiable()) {
            throw new InvalidArgumentException(sprintf('%s must be a concrete subclass of %s.', $class, Component::class));
        }

        $state = [];

        foreach ($reflection->getProperties() as $property) {
            $isState = $property->getAttributes(State::class) !== [];
            $input = $property->getAttributes(Input::class)[0] ?? null;

            if (!$isState && $input === null) {
                continue;
            }

            $where = sprintf('%s::$%s', $class, $property->getName());

            if ($isState && $input !== null) {
                throw new InvalidArgumentException($where . ' cannot be both #[State] and #[Input].');
            }

            if (!$property->isPublic() || $property->isStatic() || $property->isReadOnly()) {
                throw new InvalidArgumentException($where . ' must be a public, non-static, non-readonly property to be component state.');
            }

            $type = $property->getType();

            if (!$type instanceof ReflectionNamedType || !in_array($type->getName(), self::STATE_TYPES, true)) {
                throw new InvalidArgumentException($where . ' must be typed int, float, string, bool or array (optionally nullable).');
            }

            if ($input !== null && !in_array($type->getName(), self::SCALAR_TYPES, true)) {
                throw new InvalidArgumentException($where . ' is an #[Input] and must be int, float, string or bool.');
            }

            $state[$property->getName()] = [
                'type' => $type->getName(),
                'nullable' => $type->allowsNull(),
                'writable' => $input !== null,
                'max' => $input !== null ? max(1, $input->newInstance()->max) : 0,
            ];
        }

        return new self($class, $state, self::actions($reflection));
    }

    /**
     * @param ReflectionClass<Component> $reflection
     * @return array<string, array{params: list<array{name: string, type: string, nullable: bool, hasDefault: bool, default: mixed}>, ability: ?string}>
     */
    private static function actions(ReflectionClass $reflection): array
    {
        $actions = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attribute = $method->getAttributes(Action::class)[0] ?? null;

            if ($attribute === null) {
                continue;
            }

            $where = sprintf('%s::%s()', $reflection->getName(), $method->getName());

            if ($method->isStatic() || $method->isAbstract() || $method->isConstructor() || str_starts_with($method->getName(), '__') || $method->getDeclaringClass()->getName() === Component::class) {
                throw new InvalidArgumentException($where . ' cannot be an action.');
            }

            $params = [];

            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof ReflectionNamedType || !in_array($type->getName(), self::SCALAR_TYPES, true) || $parameter->isVariadic()) {
                    throw new InvalidArgumentException(sprintf('%s: parameter $%s must be typed int, float, string or bool.', $where, $parameter->getName()));
                }

                $params[] = [
                    'name' => $parameter->getName(),
                    'type' => $type->getName(),
                    'nullable' => $type->allowsNull(),
                    'hasDefault' => $parameter->isDefaultValueAvailable(),
                    'default' => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : null,
                ];
            }

            $actions[$method->getName()] = ['params' => $params, 'ability' => $attribute->newInstance()->authorize];
        }

        return $actions;
    }

    /**
     * @return array<string, mixed> current values of the state properties
     */
    public function export(Component $component): array
    {
        $values = [];

        foreach (array_keys($this->state) as $name) {
            $property = new ReflectionProperty($component, $name);
            $values[$name] = $property->isInitialized($component) ? $property->getValue($component) : null;
        }

        return $values;
    }
}
