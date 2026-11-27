<?php

namespace App\Enums;

enum CommissionKind: string
{
    /** A share of what the line brought in, after discounts and returns. */
    case Percent = 'percent';
    /** A fixed amount for each one done. */
    case Flat = 'flat';

    public function label(): string
    {
        return match ($this) {
            self::Percent => '% of the line',
            self::Flat => 'Rs per one',
        };
    }
}
