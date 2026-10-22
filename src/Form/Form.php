<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Form;

use Ethernize\Pulse\ComponentFactory;
use Ethernize\Pulse\Exception\PulseException;
use Ethernize\Pulse\Exception\ValidationFailed;
use Ethernize\Pulse\Validation\Validator;
use InvalidArgumentException;

/**
 * A typed set of form values for a Pulse component.
 *
 *   final class ProductData extends Form
 *   {
 *       #[Field('required|string|max:120', max: 120)]
 *       public string $name = '';
 *
 *       #[Field('required|int|min:0')]
 *       public int $price_cents = 0;
 *
 *       #[Rows(Variant::class, max: 10)]
 *       public array $variants = [];
 *   }
 *
 *   final class Products extends Component
 *   {
 *       #[Input]
 *       public ProductData $form;
 *   }
 *
 * In the template the fields are plain names: pulse:model="form.name", :value="form.name" and
 * pulse:model="form.variants.0.label". What the browser can do is exactly what the class declares: write a
 * #[Field], or write a field of a row that already exists. It cannot add properties, rows, fields of other
 * types, arrays or objects. The form is state, separate from any entity: to store it, pass values() to a
 * model's create()/fill() (which has its own list of fillable properties) or copy fields in code.
 */
abstract class Form
{
    public static function metadata(): FormMetadata
    {
        return FormMetadata::for(static::class);
    }

    /**
     * Assigns values by name, converting each to its declared type. A name that is not a #[Field] or #[Rows]
     * makes the call fail before anything is assigned.
     *
     * @param array<string, mixed> $values
     * @throws InvalidArgumentException
     */
    final public function fill(array $values): static
    {
        return $this->assign($values, false);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function assign(array $values, bool $lenient): static
    {
        $meta = static::metadata();
        $unknown = array_values(array_filter(array_map('strval', array_keys($values)), static fn (string $name): bool => !isset($meta->fields[$name]) && !isset($meta->rows[$name])));

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('%s has no field named: %s.', static::class, implode(', ', $unknown)));
        }

        $assign = [];

        foreach ($values as $name => $value) {
            $assign[$name] = isset($meta->fields[$name]) ? self::convert($name, $meta->fields[$name], $value) : $this->rowsFrom($name, $value, $meta->rows[$name], $lenient);
        }

        foreach ($assign as $name => $value) {
            $this->{$name} = $value;
        }

        return $this;
    }

    /**
     * A form filled from an array or from the public properties of an object (a model, for instance). Only the
     * names the form declares are read; everything else in the source is ignored, so copying from an entity
     * never exposes more than the form lists.
     *
     * @param array<string, mixed>|object $source
     */
    final public static function from(array|object $source): static
    {
        $values = is_array($source) ? $source : get_object_vars($source);
        $meta = static::metadata();
        $form = new static();

        $form->assign(array_intersect_key($values, $meta->fields + $meta->rows), true);

        return $form;
    }

    /**
     * The declared values, as typed PHP values: scalars by name and, for each #[Rows], a list of rows.
     *
     * @return array<string, mixed>
     */
    final public function values(): array
    {
        $meta = static::metadata();
        $vars = get_object_vars($this);
        $values = [];

        foreach ($meta->fields as $name => $field) {
            $values[$name] = array_key_exists($name, $vars) ? $vars[$name] : self::zero($field);
        }

        foreach ($meta->rows as $name => $row) {
            $values[$name] = array_values(array_map(static fn (mixed $item): array => (array) $item, (array) ($vars[$name] ?? [])));
        }

        return $values;
    }

    /**
     * What goes into a snapshot.
     *
     * @return array<string, mixed>
     * @internal
     */
    final public function toState(): array
    {
        return $this->values();
    }

    /**
     * Rebuilds a form from a snapshot, requiring every value to have exactly its declared type.
     *
     * @param array<mixed> $state
     * @throws PulseException when the state does not fit the form
     * @internal
     */
    final public static function fromState(array $state): static
    {
        $meta = static::metadata();
        $form = new static();

        foreach ($state as $name => $value) {
            if (!is_string($name) || (!isset($meta->fields[$name]) && !isset($meta->rows[$name]))) {
                throw PulseException::invalidSnapshot();
            }

            if (isset($meta->fields[$name])) {
                $field = $meta->fields[$name];

                if ($value === null ? !$field['nullable'] : !self::hasType($value, $field['type'])) {
                    throw PulseException::invalidSnapshot();
                }

                $form->{$name} = $field['type'] === 'float' && is_int($value) ? (float) $value : $value;
                continue;
            }

            $row = $meta->rows[$name];

            if (!is_array($value) || !array_is_list($value) || count($value) > $row['max']) {
                throw PulseException::invalidSnapshot();
            }

            $form->{$name} = array_map(static fn (mixed $item): array => is_array($item) ? ($row['form']::fromState($item))->values() : throw PulseException::invalidSnapshot(), $value);
        }

        return $form;
    }

    /**
     * Writes one value sent by the browser: a field ["name"], or a field of an existing row ["lines", "2", "qty"].
     * Any other path is refused.
     *
     * @param list<string> $path
     * @throws PulseException
     * @internal
     */
    final public function set(array $path, mixed $value): void
    {
        $meta = static::metadata();

        if (count($path) === 1 && isset($meta->fields[$path[0]])) {
            $this->{$path[0]} = self::fromBrowser($meta->fields[$path[0]], $value);

            return;
        }

        if (count($path) === 3 && isset($meta->rows[$path[0]]) && preg_match('/^\d{1,3}$/', $path[1]) === 1) {
            $rows = get_object_vars($this)[$path[0]] ?? [];
            $index = (int) $path[1];
            $rowMeta = FormMetadata::for($meta->rows[$path[0]]['form']);

            if (isset($rows[$index]) && isset($rowMeta->fields[$path[2]])) {
                $rows[$index][$path[2]] = self::fromBrowser($rowMeta->fields[$path[2]], $value);
                $this->{$path[0]} = $rows;

                return;
            }
        }

        throw PulseException::forbidden();
    }

    /**
     * Adds a row to a #[Rows] list, from the row form's defaults and the given values. Returns false, and
     * adds nothing, when the list is already at its maximum.
     *
     * @param array<string, mixed> $values
     */
    final public function addRow(string $collection, array $values = []): bool
    {
        $row = static::metadata()->rows[$collection] ?? throw new InvalidArgumentException(sprintf('%s has no #[Rows] named %s.', static::class, $collection));
        $rows = $this->values()[$collection];

        if (count($rows) >= $row['max']) {
            return false;
        }

        $rows[] = (new $row['form']())->fill($values)->values();
        $this->{$collection} = $rows;

        return true;
    }

    /**
     * Removes the row at $index, keeping the others in order. Returns false when there is no such row.
     */
    final public function removeRow(string $collection, int $index): bool
    {
        if (!isset(static::metadata()->rows[$collection])) {
            throw new InvalidArgumentException(sprintf('%s has no #[Rows] named %s.', static::class, $collection));
        }

        $rows = $this->values()[$collection];

        if (!isset($rows[$index])) {
            return false;
        }

        unset($rows[$index]);
        $this->{$collection} = array_values($rows);

        return true;
    }

    /**
     * Validates every field and every row with the rules the class declares. On success it returns the
     * values (see values()). On failure it throws ValidationFailed whose errors are a tree: a list of messages
     * per field, "lines" => [2 => ['qty' => [...]], '_' => [...]] for rows, where "_" holds messages about the
     * list itself (too few or too many rows).
     *
     * @param array<string, string> $messages texts by "field.rule", "rule" or "collection.field.rule"
     * @param array<string, string> $labels   how fields are called in messages
     * @return array<string, mixed>
     * @throws ValidationFailed
     */
    final public function validate(Validator $validator, array $messages = [], array $labels = []): array
    {
        $meta = static::metadata();
        $values = $this->values();
        $rules = [];

        foreach ($meta->fields as $name => $field) {
            $rules[$name] = $field['rules'];
        }

        [, $errors] = $validator->check($values, $rules, $messages, $labels);
        /** @var array<string, mixed> $errors */

        foreach ($meta->rows as $name => $row) {
            $rowMeta = FormMetadata::for($row['form']);
            $rowRules = [];

            foreach ($rowMeta->fields as $field => $definition) {
                $rowRules[$field] = $definition['rules'];
            }

            $count = count($values[$name]);
            $label = $labels[$name] ?? Validator::label($name);

            if ($count < $row['min']) {
                $errors[$name]['_'][] = strtr($messages[$name . '.min'] ?? $messages['rows.min'] ?? 'Add at least :argument item(s) to :label.', [':argument' => (string) $row['min'], ':label' => $label]);
            }

            if ($count > $row['max']) {
                $errors[$name]['_'][] = strtr($messages[$name . '.max'] ?? $messages['rows.max'] ?? 'The :label list can have at most :argument item(s).', [':argument' => (string) $row['max'], ':label' => $label]);
            }

            $scoped = [];

            foreach ($messages as $key => $text) {
                $scoped[str_starts_with($key, $name . '.') ? substr($key, strlen($name) + 1) : $key] = $text;
            }

            foreach ($values[$name] as $index => $data) {
                [, $rowErrors] = $validator->check($data, $rowRules, $scoped, $labels);

                if ($rowErrors !== []) {
                    $errors[$name][$index] = $rowErrors;
                }
            }
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors);
        }

        return $values;
    }

    /**
     * The values as a flat map of the names pulse:model uses: "form.name", "form.lines.0.qty".
     *
     * @return array<string, bool|int|float|string|null>
     * @internal
     */
    final public function flatten(string $prefix): array
    {
        $flat = [];

        foreach ($this->values() as $name => $value) {
            if (!isset(static::metadata()->rows[$name])) {
                $flat[$prefix . '.' . $name] = $value;
                continue;
            }

            foreach ($value as $index => $row) {
                foreach ($row as $field => $item) {
                    $flat[$prefix . '.' . $name . '.' . $index . '.' . $field] = $item;
                }
            }
        }

        return $flat;
    }

    /**
     * @param array{type: string, nullable: bool, rules: string|list<string>, max: int} $field
     */
    private static function convert(string $name, array $field, mixed $value): bool|int|float|string|null
    {
        try {
            return self::fromBrowser($field, $value);
        } catch (PulseException) {
            throw new InvalidArgumentException(sprintf('The value for "%s" is not a valid %s%s.', $name, $field['nullable'] ? '?' : '', $field['type']));
        }
    }

    /**
     * @param array{type: string, nullable: bool, rules: string|list<string>, max: int} $field
     * @throws PulseException
     */
    private static function fromBrowser(array $field, mixed $value): bool|int|float|string|null
    {
        $coerced = ComponentFactory::coerce($value, $field['type'], $field['nullable'], true);

        if (is_string($coerced) && mb_strlen($coerced) > $field['max']) {
            throw new PulseException(PulseException::INVALID_INPUT, 'A value is too long.', 422);
        }

        return $coerced;
    }

    /**
     * @param array{form: class-string<Form>, min: int, max: int} $row
     * @return list<array<string, mixed>>
     */
    private function rowsFrom(string $name, mixed $value, array $row, bool $lenient): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > $row['max']) {
            throw new InvalidArgumentException(sprintf('"%s" takes a list of at most %d rows.', $name, $row['max']));
        }

        return array_map(static fn (mixed $item): array => match (true) {
            is_array($item) => ($lenient ? $row['form']::from($item) : (new $row['form']())->fill($item))->values(),
            is_object($item) => $row['form']::from($item)->values(),
            default => throw new InvalidArgumentException('Each row is an array or an object.'),
        }, $value);
    }

    /**
     * @param array{type: string, nullable: bool, rules: string|list<string>, max: int} $field
     */
    private static function zero(array $field): bool|int|float|string|null
    {
        return $field['nullable'] ? null : match ($field['type']) {
            'int' => 0,
            'float' => 0.0,
            'bool' => false,
            default => '',
        };
    }

    private static function hasType(mixed $value, string $type): bool
    {
        return match ($type) {
            'int' => is_int($value),
            'float' => is_float($value) || is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            default => false,
        };
    }
}
