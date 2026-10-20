<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Validation;

use InvalidArgumentException;

/**
 * A small rule engine for component input.
 *
 * Rules are strings separated by "|": required, nullable, string, int,
 * numeric, bool, email, min:N, max:N, in:a,b,c. For strings min/max compare
 * the length in characters; for numbers they compare the value.
 *
 * "nullable" lets an empty value skip the remaining rules. Without "required"
 * an empty value also skips them.
 */
final class Validator
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed>                  $data
     * @param array<string, string|list<string>>    $rules
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} validated values and errors
     */
    public static function validate(array $data, array $rules): array
    {
        $clean = [];
        $errors = [];

        foreach ($rules as $field => $definition) {
            $value = $data[$field] ?? null;
            $parsed = self::parse($definition);
            $empty = $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');

            if (array_key_exists('required', $parsed) && $empty) {
                $errors[$field][] = sprintf('The %s field is required.', self::label($field));
                continue;
            }

            if ($empty) {
                $clean[$field] = $value;
                continue;
            }

            foreach ($parsed as $rule => $argument) {
                $message = self::check($field, $value, $rule, $argument);

                if ($message !== null) {
                    $errors[$field][] = $message;
                }
            }

            if (!isset($errors[$field])) {
                $clean[$field] = $value;
            }
        }

        return [$clean, $errors];
    }

    /**
     * @param string|list<string> $definition
     * @return array<string, ?string>
     */
    private static function parse(string|array $definition): array
    {
        $rules = [];

        foreach (is_array($definition) ? $definition : explode('|', $definition) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            [$name, $argument] = array_pad(explode(':', $part, 2), 2, null);

            if (!in_array($name, ['required', 'nullable', 'string', 'int', 'numeric', 'bool', 'email', 'min', 'max', 'in'], true)) {
                throw new InvalidArgumentException(sprintf('Unknown validation rule "%s".', $name));
            }

            if (in_array($name, ['min', 'max', 'in'], true) && ($argument === null || $argument === '')) {
                throw new InvalidArgumentException(sprintf('The "%s" rule needs an argument.', $name));
            }

            $rules[$name] = $argument;
        }

        return $rules;
    }

    private static function check(string $field, mixed $value, string $rule, ?string $argument): ?string
    {
        $label = self::label($field);

        return match ($rule) {
            'string' => is_string($value) ? null : sprintf('The %s field must be text.', $label),
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) ? null : sprintf('The %s field must be a whole number.', $label),
            'numeric' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? null : sprintf('The %s field must be a number.', $label),
            'bool' => is_bool($value) ? null : sprintf('The %s field must be true or false.', $label),
            'email' => is_string($value) && strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? null : sprintf('The %s field must be a valid email address.', $label),
            'min' => self::size($value) >= (float) $argument ? null : sprintf('The %s field must be at least %s%s.', $label, $argument, is_string($value) ? ' characters' : ''),
            'max' => self::size($value) <= (float) $argument ? null : sprintf('The %s field must not be greater than %s%s.', $label, $argument, is_string($value) ? ' characters' : ''),
            'in' => in_array((string) $value, explode(',', (string) $argument), true) ? null : sprintf('The selected %s is invalid.', $label),
            default => null,
        };
    }

    private static function size(mixed $value): float
    {
        return match (true) {
            is_string($value) => (float) mb_strlen($value),
            is_array($value) => (float) count($value),
            is_int($value), is_float($value) => (float) $value,
            default => 0.0,
        };
    }

    public static function label(string $field): string
    {
        return strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $field))));
    }
}
