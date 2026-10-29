<?php

namespace App\Enums;

enum PartyLedgerDirection: string
{
    case Debit = 'debit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Debit => 'Debit — customer owes more',
            self::Credit => 'Credit — customer owes less',
        };
    }

    /**
     * +1 increases what the customer owes, -1 decreases it.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Debit => 1,
            self::Credit => -1,
        };
    }
}
