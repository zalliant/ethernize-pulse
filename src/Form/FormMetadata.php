<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Form;

use Ethernize\Pulse\Attribute\Field;
use Ethernize\Pulse\Attribute\Rows;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * What a Form class declares, read once by reflection and checked strictly, like ComponentMetadata does for
 * components: fields are public scalar properties marked #[Field], collections are public array properties
 * marked #[Rows] whose rows are shaped by another Form. Everything else in the class is invisible to the browser.
 *
 * @internal
 */
final class FormMetadata
{
    private const SCALARS = ['int', 'float', 'string', 'bool'];

    /** @var array<class-string, self> */
    private static array $cache = [];

    /**
     * @param array<string, array{type: string, nullable: bool, rules: string|list<string>, max: int}> $fields
     * @param array<string, array{form: class-string<Form>, min: int, max: int}> $rows
     */
    private function __construct(
        public readonly string $class,
        public readonly array $fields,
        public readonly array $rows,
    ) {
    }

    /**
     * @param class-string<Form> $class
     * @throws InvalidArgumentException when the form declares something Pulse cannot handle safely
     */
    public static function for(string $class): self
    {
        return self::$cache[$class] ??= self::build($class);
    }

    /**
     * @param class-string<Form> $class
     */
    private static function build(string $class): self
    {
        $reflection = new ReflectionClass($class);

        if (!$reflection->isSubclassOf(Form::class) || !$reflection->isInstantiable()) {
            throw new InvalidArgumentException(sprintf('%s must be a concrete subclass of %s.', $class, Form::class));
        }

        $fields = [];
        $rows = [];

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            $field = $property->getAttributes(Field::class)[0] ?? null;
            $collection = $property->getAttributes(Rows::class)[0] ?? null;

            if ($field === null && $collection === null) {
                continue;
            }

            $where = sprintf('%s::$%s', $class, $property->getName());

            if ($field !== null && $collection !== null) {
                throw new InvalidArgumentException($where . ' cannot be both #[Field] and #[Rows].');
            }

            if ($property->isStatic() || $property->isReadOnly() || preg_match('/^[a-z][A-Za-z0-9_]*$/', $property->getName()) !== 1) {
                throw new InvalidArgumentException($where . ' must be a public, non-static, non-readonly property whose name starts with a lowercase letter.');
            }

            $type = $property->getType();

            if (!$type instanceof ReflectionNamedType) {
                throw new InvalidArgumentException($where . ' needs a declared type.');
            }

            if ($field !== null) {
                if (!in_array($type->getName(), self::SCALARS, true)) {
                    throw new InvalidArgumentException($where . ' is a #[Field] and must be int, float, string or bool (optionally nullable).');
                }

                $fields[$property->getName()] = ['type' => $type->getName(), 'nullable' => $type->allowsNull(), 'rules' => $field->newInstance()->rules, 'max' => max(1, $field->newInstance()->max)];
                continue;
            }

            $attribute = $collection->newInstance();

            if ($type->getName() !== 'array' || $type->allowsNull()) {
                throw new InvalidArgumentException($where . ' is #[Rows] and must be typed array.');
            }

            if (!is_subclass_of($attribute->form, Form::class)) {
                throw new InvalidArgumentException(sprintf('%s: %s is not a Form class.', $where, $attribute->form));
            }

            if ($attribute->min < 0 || $attribute->max < 1 || $attribute->max > 200 || $attribute->min > $attribute->max) {
                throw new InvalidArgumentException($where . ': #[Rows] takes min >= 0 and max between 1 and 200 (with min <= max).');
            }

            if (self::for($attribute->form)->rows !== []) {
                throw new InvalidArgumentException($where . ': rows cannot contain further #[Rows]. Flatten the structure.');
            }

            $rows[$property->getName()] = ['form' => $attribute->form, 'min' => $attribute->min, 'max' => $attribute->max];
        }

        if ($fields === [] && $rows === []) {
            throw new InvalidArgumentException(sprintf('%s declares no #[Field] or #[Rows] property.', $class));
        }

        return new self($class, $fields, $rows);
    }
}
