<?php

namespace App\Enums;

enum PayslipStatus: string
{
    case Draft = 'draft';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }

    public function badge(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Paid => 'success',
            self::Cancelled => 'danger',
        };
    }
}
