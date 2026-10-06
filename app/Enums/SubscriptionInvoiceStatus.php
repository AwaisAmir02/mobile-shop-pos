<?php

namespace App\Enums;

enum SubscriptionInvoiceStatus: string
{
    case Unpaid = 'unpaid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Unpaid => 'Unpaid',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }
}
