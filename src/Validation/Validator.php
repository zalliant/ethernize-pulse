<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Validation;

use Closure;
use InvalidArgumentException;

/**
 * A small rule engine for component input.
 *
 * Rules are strings separated by "|", or a list that may also hold rule objects and closures:
 *
 *   required, nullable, string, int, numeric, bool, email, url, date, decimal:N, same:field,
 *   min:N, max:N, between:A,B, in:a,b,c, not_in:a,b,c, regex:/pattern/
 *
 * For strings min/max/between compare the length in characters; for numbers they compare the value.
 * "nullable" lets an empty value skip the remaining rules; without "required" an empty value also skips them.
 *
 * Applications add rules with extend() (or the pulse.validation.rules setting) and change texts per field
 * ("name.required"), per rule ("required"), per language (a locale or a catalog), with labels for the names
 * of the fields. The static validate() keeps working as before, in English.
 */
final class Validator
{
    private const BUILT_IN = ['required', 'nullable', 'string', 'int', 'numeric', 'bool', 'email', 'url', 'date', 'decimal', 'same', 'min', 'max', 'between', 'in', 'not_in', 'regex'];

    /** @var array<string, string> */
    private array $catalog;

    /** @var array<string, Rule|Closure> */
    private array $custom = [];

    /**
     * @param string|array<string, string> $locale "en", "pt-BR", or a catalog (the English texts fill what it leaves out)
     */
    public function __construct(string|array $locale = 'en')
    {
        $this->catalog = is_array($locale) ? $locale + Messages::catalog('en') : Messages::catalog($locale);
    }

    /**
     * Makes a rule available by name: "cpf" in 'required|cpf'. A closure receives (value, data, field) and
     * returns true when the value is valid, or false / a message when it is not.
     *
     * @param Rule|Closure(mixed, array<string, mixed>, string): (bool|string) $rule
     */
    public function extend(string $name, Rule|Closure $rule): self
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,30}$/', $name) !== 1 || in_array($name, self::BUILT_IN, true)) {
            throw new InvalidArgumentException(sprintf('"%s" cannot be a custom rule name: use lowercase letters, digits and "_", and not a built-in name.', $name));
        }

        $this->custom[$name] = $rule;

        return $this;
    }

    /**
     * @param array<string, mixed>                                  $data
     * @param array<string, string|list<string|Rule|Closure>>       $rules
     * @param array<string, string>                                 $messages texts by "field.rule" or "rule"
     * @param array<string, string>                                 $labels   how fields are called in messages
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>} validated values and errors
     */
    public function check(array $data, array $rules, array $messages = [], array $labels = []): array
    {
        $clean = [];
        $errors = [];

        foreach ($rules as $field => $definition) {
            $value = $data[$field] ?? null;
            $parsed = $this->parse($definition);
            $empty = $value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '');
            $label = $labels[$field] ?? self::label($field);

            if (array_key_exists('required', $parsed) && $empty) {
                $errors[$field][] = $this->text($field, 'required', null, $label, null, $messages);
                continue;
            }

            if ($empty) {
                $clean[$field] = $value;
                continue;
            }

            foreach ($parsed as $key => [$rule, $argument]) {
                $message = $this->run($field, $label, $value, $data, $rule, $argument, $messages);

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
     * Validation with the built-in rules and English messages.
     *
     * @param array<string, mixed>                            $data
     * @param array<string, string|list<string|Rule|Closure>> $rules
     * @return array{0: array<string, mixed>, 1: array<string, list<string>>}
     */
    public static function validate(array $data, array $rules): array
    {
        return (new self())->check($data, $rules);
    }

    public static function label(string $field): string
    {
        return strtolower(trim((string) preg_replace('/(?<!^)[A-Z]/', ' $0', str_replace('_', ' ', $field))));
    }

    /**
     * @param string|list<string|Rule|Closure> $definition
     * @return array<string, array{0: string|Rule|Closure, 1: ?string}> rule key => [rule, argument]
     */
    private function parse(string|array $definition): array
    {
        $rules = [];
        $anonymous = 0;

        foreach (is_array($definition) ? $definition : explode('|', $definition) as $part) {
            if ($part instanceof Rule || $part instanceof Closure) {
                $rules['#' . $anonymous++] = [$part, null];
                continue;
            }

            $part = trim((string) $part);

            if ($part === '') {
                continue;
            }

            [$name, $argument] = array_pad(explode(':', $part, 2), 2, null);

            if (!in_array($name, self::BUILT_IN, true) && !isset($this->custom[$name])) {
                throw new InvalidArgumentException(sprintf('Unknown validation rule "%s".', $name));
            }

            if (in_array($name, ['min', 'max', 'in', 'not_in', 'between', 'regex', 'decimal', 'same'], true) && ($argument === null || $argument === '')) {
                throw new InvalidArgumentException(sprintf('The "%s" rule needs an argument.', $name));
            }

            if ($name === 'between' && count(explode(',', (string) $argument)) !== 2) {
                throw new InvalidArgumentException('The "between" rule takes two numbers: between:1,10.');
            }

            if ($name === 'regex' && @preg_match((string) $argument, '') === false) {
                throw new InvalidArgumentException('The "regex" rule needs a valid pattern with delimiters: regex:/^[a-z]+$/. Put it in a list if it contains "|".');
            }

            $rules[$name] = [$name, $argument];
        }

        return $rules;
    }

    /**
     * @param string|Rule|Closure   $rule
     * @param array<string, mixed>  $data
     * @param array<string, string> $messages
     */
    private function run(string $field, string $label, mixed $value, array $data, string|Rule|Closure $rule, ?string $argument, array $messages): ?string
    {
        $name = is_string($rule) ? $rule : ($rule instanceof Rule ? $rule::class : 'custom');

        if (is_string($rule) && isset($this->custom[$rule])) {
            $rule = $this->custom[$rule];
        }

        if ($rule instanceof Rule) {
            return $rule->passes($value, $data) ? null : $this->override($field, $messages, $name) ?? $this->fill($rule->message(), $label, $argument);
        }

        if ($rule instanceof Closure) {
            $result = $rule($value, $data, $field);

            return match (true) {
                $result === true => null,
                is_string($result) && $result !== '' => $this->override($field, $messages, $name) ?? $this->fill($result, $label, $argument),
                default => $this->text($field, 'invalid', null, $label, $argument, $messages),
            };
        }

        $kind = is_string($value) ? 'string' : 'numeric';

        $passes = match ($rule) {
            'nullable' => true,
            'string' => is_string($value),
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'numeric' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'bool' => is_bool($value),
            'email' => is_string($value) && strlen($value) <= 254 && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
            'url' => is_string($value) && strlen($value) <= 2048 && preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false,
            'date' => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4)),
            'decimal' => (is_int($value) || is_float($value) || is_string($value)) && preg_match('/^-?\d+(\.\d{1,' . max(1, (int) $argument) . '})?$/', (string) $value) === 1,
            'same' => ($data[$argument] ?? null) === $value,
            'min' => self::size($value) >= (float) $argument,
            'max' => self::size($value) <= (float) $argument,
            'between' => self::size($value) >= (float) explode(',', (string) $argument)[0] && self::size($value) <= (float) explode(',', (string) $argument)[1],
            'in' => in_array((string) $value, explode(',', (string) $argument), true),
            'not_in' => !in_array((string) $value, explode(',', (string) $argument), true),
            'regex' => is_string($value) && preg_match((string) $argument, $value) === 1,
            default => true,
        };

        return $passes ? null : $this->text($field, (string) $rule, $kind, $label, $argument, $messages);
    }

    /**
     * @param array<string, string> $messages
     */
    private function text(string $field, string $rule, ?string $kind, string $label, ?string $argument, array $messages): string
    {
        $template = $messages[$field . '.' . $rule]
            ?? $messages[$rule]
            ?? ($kind !== null ? ($this->catalog[$rule . '.' . $kind] ?? null) : null)
            ?? $this->catalog[$rule]
            ?? $this->catalog['invalid'];

        return $this->fill($template, $label, $argument);
    }

    /**
     * @param array<string, string> $messages
     */
    private function override(string $field, array $messages, string $rule): ?string
    {
        return isset($messages[$field . '.' . $rule]) ? $messages[$field . '.' . $rule] : ($messages[$rule] ?? null);
    }

    private function fill(string $template, string $label, ?string $argument): string
    {
        $between = explode(',', (string) $argument) + [1 => ''];

        return strtr($template, [':label' => $label, ':argument' => (string) $argument, ':min' => $between[0], ':max' => $between[1]]);
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
}
