<?php

declare(strict_types=1);

namespace Ethernize\Pulse;

use InvalidArgumentException;

/**
 * Explicit map from component names (kebab-case, as in "todo-list") to PHP
 * classes. The name is what travels in snapshots; the class name never leaves
 * the server. A component is usable in templates as the PascalCase form of
 * its name: "todo-list" becomes <TodoList />.
 */
final class ComponentRegistry
{
    private const NAME_PATTERN = '/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/';

    /** @var array<string, class-string<Component>> */
    private array $classes = [];

    /**
     * @param array<string, class-string<Component>> $components
     */
    public function __construct(array $components = [])
    {
        foreach ($components as $name => $class) {
            $this->register($name, $class);
        }
    }

    /**
     * @param class-string<Component> $class
     * @throws InvalidArgumentException when the name is invalid or the class declares unsupported state or actions
     */
    public function register(string $name, string $class): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid component name "%s". Use kebab-case, for example "todo-list".', $name));
        }

        if (!class_exists($class)) {
            throw new InvalidArgumentException(sprintf('Component class "%s" does not exist.', $class));
        }

        ComponentMetadata::for($class);

        if (isset($this->classes[$name]) && $this->classes[$name] !== $class) {
            throw new InvalidArgumentException(sprintf('The component name "%s" is already registered.', $name));
        }

        if (in_array(self::pascal($name), ['PulseScripts'], true)) {
            throw new InvalidArgumentException(sprintf('"%s" is reserved.', $name));
        }

        $this->classes[$name] = $class;
    }

    public function has(string $name): bool
    {
        return isset($this->classes[$name]);
    }

    /**
     * @return class-string<Component>|null
     */
    public function class(string $name): ?string
    {
        return $this->classes[$name] ?? null;
    }

    /**
     * @return list<string> kebab-case names
     */
    public function names(): array
    {
        return array_keys($this->classes);
    }

    public static function pascal(string $name): string
    {
        return implode('', array_map('ucfirst', explode('-', $name)));
    }

    /**
     * The registered kebab-case name for a PascalCase tag, or null.
     */
    public function fromPascal(string $pascal): ?string
    {
        foreach (array_keys($this->classes) as $name) {
            if (self::pascal($name) === $pascal) {
                return $name;
            }
        }

        return null;
    }
}
