<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Validation;

use InvalidArgumentException;

/**
 * The texts of the validation messages, by locale. ":label" is the name of the field, ":argument" the value
 * given to the rule ("min:3"), ":min" and ":max" the two ends of "between:1,5". Applications replace any
 * text with Validator's messages (by "field.rule" or by "rule") or give a whole catalog for another language.
 */
final class Messages
{
    private const EN = [
        'required' => 'The :label field is required.',
        'string' => 'The :label field must be text.',
        'int' => 'The :label field must be a whole number.',
        'numeric' => 'The :label field must be a number.',
        'bool' => 'The :label field must be true or false.',
        'email' => 'The :label field must be a valid email address.',
        'min.string' => 'The :label field must be at least :argument characters.',
        'min.numeric' => 'The :label field must be at least :argument.',
        'max.string' => 'The :label field must not be greater than :argument characters.',
        'max.numeric' => 'The :label field must not be greater than :argument.',
        'between.string' => 'The :label field must be between :min and :max characters.',
        'between.numeric' => 'The :label field must be between :min and :max.',
        'in' => 'The selected :label is invalid.',
        'not_in' => 'The selected :label is invalid.',
        'regex' => 'The :label field has an invalid format.',
        'url' => 'The :label field must be a valid URL.',
        'date' => 'The :label field must be a valid date (YYYY-MM-DD).',
        'decimal' => 'The :label field must be a number with at most :argument decimal places.',
        'same' => 'The :label field must match :argument.',
        'invalid' => 'The :label field is invalid.',
        'rows.min' => 'Add at least :argument item(s) to :label.',
        'rows.max' => 'The :label list can have at most :argument item(s).',
    ];

    private const PT_BR = [
        'required' => 'O campo :label é obrigatório.',
        'string' => 'O campo :label deve ser um texto.',
        'int' => 'O campo :label deve ser um número inteiro.',
        'numeric' => 'O campo :label deve ser um número.',
        'bool' => 'O campo :label deve ser verdadeiro ou falso.',
        'email' => 'O campo :label deve ser um e-mail válido.',
        'min.string' => 'O campo :label deve ter pelo menos :argument caracteres.',
        'min.numeric' => 'O campo :label deve ser no mínimo :argument.',
        'max.string' => 'O campo :label não pode ter mais de :argument caracteres.',
        'max.numeric' => 'O campo :label não pode ser maior que :argument.',
        'between.string' => 'O campo :label deve ter entre :min e :max caracteres.',
        'between.numeric' => 'O campo :label deve estar entre :min e :max.',
        'in' => 'O valor escolhido para :label é inválido.',
        'not_in' => 'O valor escolhido para :label é inválido.',
        'regex' => 'O campo :label tem um formato inválido.',
        'url' => 'O campo :label deve ser uma URL válida.',
        'date' => 'O campo :label deve ser uma data válida (AAAA-MM-DD).',
        'decimal' => 'O campo :label deve ser um número com no máximo :argument casas decimais.',
        'same' => 'O campo :label deve ser igual a :argument.',
        'invalid' => 'O campo :label é inválido.',
        'rows.min' => 'Adicione pelo menos :argument item(ns) em :label.',
        'rows.max' => 'A lista :label pode ter no máximo :argument item(ns).',
    ];

    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     * @throws InvalidArgumentException for a locale without a catalog
     */
    public static function catalog(string $locale): array
    {
        return match (strtolower(str_replace('_', '-', $locale))) {
            'en', 'en-us' => self::EN,
            'pt-br', 'pt' => self::PT_BR,
            default => throw new InvalidArgumentException(sprintf('There is no message catalog for "%s". Built in: en, pt-BR. Give an array of messages for another language.', $locale)),
        };
    }
}
