<?php

namespace App\Enums;

enum PaymentAccountKind: string
{
    case Bank = 'bank';
    case JazzCash = 'jazzcash';
    case Easypaisa = 'easypaisa';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Bank => 'Bank account',
            self::JazzCash => 'JazzCash',
            self::Easypaisa => 'Easypaisa',
            self::Other => 'Other',
        };
    }

    /** What the provider field asks for; wallets are named by their kind already. */
    public function needsProvider(): bool
    {
        return $this === self::Bank || $this === self::Other;
    }
}
