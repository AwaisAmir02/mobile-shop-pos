<?php

namespace App\Enums;

enum StockInPaymentSource: string
{
    case Initial = 'initial';
    case Payment = 'payment';
    case MarkPaid = 'mark_paid';
    case Backfill = 'backfill';

    public function label(): string
    {
        return match ($this) {
            self::Initial => 'Paid at purchase',
            self::Payment => 'Payment made',
            self::MarkPaid => 'Marked as paid',
            self::Backfill => 'Paid before payment dates were tracked',
        };
    }
}
