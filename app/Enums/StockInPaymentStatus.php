<?php

namespace App\Enums;

enum StockInPaymentStatus: string
{
    case Paid = 'paid';
    case Unpaid = 'unpaid';
    case Partial = 'partial';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Unpaid => 'Unpaid',
            self::Partial => 'Partial',
        };
    }
}
