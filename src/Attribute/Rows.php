<?php

declare(strict_types=1);

namespace Ethernize\Pulse\Attribute;

use Attribute;

/**
 * Declares a property of a Form as a list of rows, each row shaped by another Form class. This is the only way
 * a list of structured values can be edited from the browser: the row class says which fields exist, their
 * types and their rules, and no row can have more than $max entries.
 *
 *   #[Rows(OrderLine::class, min: 1, max: 20)]
 *   public array $lines = [];
 *
 * Rows are added and removed by actions in the component (Form::addRow(), Form::removeRow()), never by the
 * browser inventing indexes. The browser can only change fields of rows that exist.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Rows
{
    /**
     * @param class-string $form the Form class of one row
     */
    public function __construct(public readonly string $form, public readonly int $min = 0, public readonly int $max = 20)
    {
    }
}
