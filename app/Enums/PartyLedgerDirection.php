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
     * The same direction worded for a vendor statement, where the balance is
     * what the shop owes the vendor.
     */
    public function vendorLabel(): string
    {
        return match ($this) {
            self::Credit => 'Credit: we owe the vendor more',
            self::Debit => 'Debit: we owe less',
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
