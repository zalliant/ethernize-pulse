<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Validation;

/**
 * A validation rule written by the application. Register it once (Validator::extend(), or the
 * pulse.validation.rules setting) and use it by name, or put an instance directly in a rules list:
 *
 *   $this->validate(['cpf' => ['required', new Cpf()]]);
 *
 * It runs only for values that are not empty; use "required" to demand one.
 */
interface Rule
{
    /**
     * @param array<string, mixed> $data every value being validated, for rules that compare fields
     */
    public function passes(mixed $value, array $data): bool;

    /**
     * The message when it fails. ":label" is replaced by the name of the field.
     */
    public function message(): string;
}
