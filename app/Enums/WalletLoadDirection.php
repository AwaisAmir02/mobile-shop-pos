<?php

namespace App\Enums;

enum WalletLoadDirection: string
{
    case CashIn = 'cash_in';
    case CashOut = 'cash_out';

    public function label(): string
    {
        return match ($this) {
            self::CashIn => 'Cash In',
            self::CashOut => 'Cash Out',
        };
    }
}
