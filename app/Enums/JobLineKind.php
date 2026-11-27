<?php

namespace App\Enums;

/** A priced line on a job card: a part from stock, or labour (billed as a service line). */
enum JobLineKind: string
{
    case Part = 'part';
    case Labour = 'labour';

    public function label(): string
    {
        return match ($this) {
            self::Part => 'Part',
            self::Labour => 'Labour',
        };
    }
}
