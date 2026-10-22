<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Attribute;

use Attribute;

/**
 * Declares a property of a Form as a field the browser may write (through pulse:model="form.name") and says
 * how it is validated. Only #[Field] and #[Rows] properties are part of a form: the form never accepts, shows
 * or stores anything else.
 *
 *   #[Field('required|string|max:120')]
 *   public string $name = '';
 *
 * $rules  validation rules (see Validator): a string with "|" or a list
 * $max    longest text accepted, in characters (only for string properties)
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Field
{
    /**
     * @param string|list<string> $rules
     */
    public function __construct(public readonly string|array $rules = '', public readonly int $max = 10000)
    {
    }
}
