<?php

namespace App\Enums;

enum BalanceLoadType: string
{
    case Balance = 'balance';
    case Package = 'package';

    public function label(): string
    {
        return match ($this) {
            self::Balance => 'Balance',
            self::Package => 'Package',
        };
    }
}
