<?php

namespace App\Enums;

enum AdvanceType: string
{
    case Given = 'given';
    case Repaid = 'repaid';
    case Deducted = 'deducted';

    public function label(): string
    {
        return match ($this) {
            self::Given => 'Advance given',
            self::Repaid => 'Repaid in cash',
            self::Deducted => 'Deducted from salary',
        };
    }

    /** What it does to the balance the employee owes. */
    public function sign(): int
    {
        return $this === self::Given ? 1 : -1;
    }
}
