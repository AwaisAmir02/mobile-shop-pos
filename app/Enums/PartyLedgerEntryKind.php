<?php

namespace App\Enums;

enum PartyLedgerEntryKind: string
{
    case Opening = 'opening';
    case Adjustment = 'adjustment';

    public function label(): string
    {
        return match ($this) {
            self::Opening => 'Opening balance',
            self::Adjustment => 'Adjustment',
        };
    }
}
